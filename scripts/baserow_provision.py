#!/usr/bin/env python3
"""
Baserow User Provisioning Script
Creates users and assigns them to appropriate workspaces with strict isolation.
"""

import json
import sys
import os

# Setup Django environment
os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'baserow.config.settings.base')

import django
django.setup()

from baserow.core.models import Workspace, WorkspaceUser
from baserow.core.user.handler import UserHandler
from baserow.core.user.exceptions import UserAlreadyExist
from django.contrib.auth import get_user_model

User = get_user_model()

# Load user data
USERS_FILE = '/tmp/baserow_users.json'

ROLE_MAP = {
    'ADMIN': WorkspaceUser.ROLE_ADMIN,
    'MEMBER': WorkspaceUser.ROLE_MEMBER,
}

def provision_users():
    """Provision users from JSON file to appropriate workspaces."""
    
    if not os.path.exists(USERS_FILE):
        print(f"ERROR: {USERS_FILE} not found!")
        sys.exit(1)
    
    with open(USERS_FILE, 'r') as f:
        workspaces_data = json.load(f)
    
    print("=" * 60)
    print("BASEROW USER PROVISIONING")
    print("=" * 60)
    
    for workspace_name, users in workspaces_data.items():
        print(f"\n--- Processing workspace: {workspace_name} ---")
        
        # Get or create workspace
        workspace, created = Workspace.objects.get_or_create(
            name=workspace_name,
            defaults={
                'order': 0,
                'per_user_permissions_enabled': False,
            }
        )
        if created:
            print(f"  [CREATED] Workspace: {workspace_name}")
        else:
            print(f"  [EXISTS]  Workspace: {workspace_name}")
        
        for user_data in users:
            email = user_data['email']
            password = user_data['password']
            role_str = user_data['role']
            name = user_data.get('name', email.split('@')[0])
            
            # Get or create user
            try:
                user, created = User.objects.get_or_create(
                    email=email,
                    defaults={
                        'username': email,
                        'first_name': name.split()[0] if ' ' in name else name,
                        'last_name': ' '.join(name.split()[1:]) if ' ' in name else '',
                        'is_active': True,
                    }
                )
                
                # Set password (always update to ensure correct)
                user.set_password(password)
                user.save()
                
                if created:
                    print(f"  [CREATED] User: {email}")
                else:
                    print(f"  [UPDATED] User: {email}")
                
                # STRICT ISOLATION: Remove user from all other workspaces first
                other_memberships = WorkspaceUser.objects.filter(user=user).exclude(workspace=workspace)
                removed_count = other_memberships.count()
                if removed_count > 0:
                    other_workspaces = [m.workspace.name for m in other_memberships]
                    other_memberships.delete()
                    print(f"    [REMOVED] From workspaces: {', '.join(other_workspaces)}")
                
                # Add/update membership in target workspace
                membership, mem_created = WorkspaceUser.objects.get_or_create(
                    user=user,
                    workspace=workspace,
                    defaults={'role': ROLE_MAP.get(role_str, WorkspaceUser.ROLE_MEMBER)}
                )
                
                if not mem_created:
                    # Update role if changed
                    if membership.role != ROLE_MAP.get(role_str, WorkspaceUser.ROLE_MEMBER):
                        membership.role = ROLE_MAP.get(role_str, WorkspaceUser.ROLE_MEMBER)
                        membership.save()
                        print(f"    [UPDATED] Role: {role_str}")
                    else:
                        print(f"    [EXISTS]  Role: {role_str}")
                else:
                    print(f"    [ASSIGNED] Role: {role_str}")
                
                # Clean up auto-created personal workspace if empty
                personal_workspaces = Workspace.objects.filter(
                    name__startswith=f"{name}'s workspace",
                    workspaceuser__isnull=True
                )
                for pw in personal_workspaces:
                    print(f"    [CLEANUP] Deleted empty personal workspace: {pw.name}")
                    pw.delete()
                
            except Exception as e:
                print(f"  [ERROR]   User: {email} - {str(e)}")
    
    print("\n" + "=" * 60)
    print("PROVISIONING COMPLETE")
    print("=" * 60)

if __name__ == '__main__':
    provision_users()
