# Gmail OAuth Integration for Supply Orders

## Purpose
Send supply order emails directly to vendors (Grainger) from the Replenishment Dashboard.

## Configuration

### Gmail OAuth Credentials
```env
GOOGLE_CLIENT_ID=<from Google Cloud Console>
GOOGLE_CLIENT_SECRET=<from Google Cloud Console>
GOOGLE_REFRESH_TOKEN=<from OAuth 2.0 Playground>
GMAIL_SENDER_EMAIL=mbfdsupport@gmail.com
```

### Feature Flag
```env
FEATURE_EMAIL_SENDING=true  # Enable email sending
```

## Service

**GmailService** ([`app/Services/GmailService.php`](../app/Services/GmailService.php))
- Auto-refreshes OAuth tokens
- Sends HTML emails via Gmail API
- Logs all send attempts
- Returns success/failure status with message ID

## Email Template

**View**: [`resources/views/emails/supply-order.blade.php`](../resources/views/emails/supply-order.blade.php)
- Professional HTML table layout
- Station, Item, SKU, Quantity columns
- MBFD branding and contact info
- Optional notes section

## Workflow

1. Admin selects low-stock items in Replenishment Dashboard
2. Clicks "Generate & Send Order Email"
3. Enters vendor email (default: orders@grainger.com)
4. Optional: Add notes
5. System creates order record with status='draft'
6. Sends email via Gmail OAuth API
7. On success: Updates order status='sent' + order lines status='ordered'
8. On failure: Updates order status='failed' + logs error

## Testing

```bash
# Test GmailService initialization
php artisan tinker
>>> app(App\Services\GmailService::class);

# Send test email
>>> $gmail = app(App\Services\GmailService::class);
>>> $gmail->sendEmail([
...   'to' => 'your-test@email.com',
...   'subject' => 'Test Supply Order',
...   'body' => '<h1>Test Email</h1><p>This is a test.</p>',
... ]);
```

## Security

- ✅ OAuth credentials stored in `.env` (git-ignored)
- ✅ Refresh token never expires (unless manually revoked)
- ✅ Access tokens auto-refresh
- ✅ No credentials in code or git history
- ✅ Feature flag allows disabling if issues occur

## Troubleshooting

**Error: "Invalid credentials"**
- Check GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env
- Verify OAuth consent screen is configured

**Error: "Token has been expired or revoked"**
- Regenerate refresh token via OAuth 2.0 Playground
- Update GOOGLE_REFRESH_TOKEN in .env

**Error: "Insufficient Permission"**
- Verify Gmail API enabled in Google Cloud Console
- Check OAuth scope includes `gmail.send`

## Gmail API Reference

- Docs: https://developers.google.com/gmail/api/reference/rest/v1/users.messages/send
- OAuth Playground: https://developers.google.com/oauthplayground
