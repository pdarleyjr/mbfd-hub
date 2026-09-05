<?php

declare(strict_types=1);

namespace App\Support\Workgroups;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupFile;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class WorkgroupAccess
{
    public function isGlobalViewer(User $user): bool
    {
        return $user->hasRole('super_admin')
            || $user->can('admin.workgroups.view')
            || $user->can('workgroup.global_access');
    }

    public function canEnterPanel(User $user): bool
    {
        return $this->isGlobalViewer($user)
            || $this->activeMembershipsFor($user)->exists();
    }

    public function canViewWorkgroup(User $user, Workgroup $workgroup): bool
    {
        return $this->isGlobalViewer($user)
            || $this->activeMembershipsFor($user)
                ->where('workgroup_id', $workgroup->id)
                ->exists();
    }

    public function canManageWorkgroup(User $user, Workgroup $workgroup): bool
    {
        if ($user->hasRole('super_admin') || $user->can('admin.workgroups.manage')) {
            return true;
        }

        return $this->activeMembershipsFor($user)
            ->where('workgroup_id', $workgroup->id)
            ->whereIn('role', ['admin', 'facilitator'])
            ->exists();
    }

    public function canManageAnyWorkgroup(User $user): bool
    {
        return $user->hasRole('super_admin')
            || $user->can('admin.workgroups.manage')
            || $this->activeMembershipsFor($user)
                ->whereIn('role', ['admin', 'facilitator'])
                ->exists();
    }

    public function canViewSession(User $user, WorkgroupSession $session): bool
    {
        $session->loadMissing('workgroup');
        $workgroup = $session->workgroup;

        return $workgroup instanceof Workgroup && $this->canViewWorkgroup($user, $workgroup);
    }

    public function canManageSession(User $user, WorkgroupSession $session): bool
    {
        $session->loadMissing('workgroup');
        $workgroup = $session->workgroup;

        return $workgroup instanceof Workgroup && $this->canManageWorkgroup($user, $workgroup);
    }

    public function canViewCandidateProduct(User $user, CandidateProduct $product): bool
    {
        $product->loadMissing('session.workgroup');
        $session = $product->session;

        return $session instanceof WorkgroupSession && $this->canViewSession($user, $session);
    }

    public function canManageCandidateProduct(User $user, CandidateProduct $product): bool
    {
        $product->loadMissing('session.workgroup');
        $session = $product->session;

        return $session instanceof WorkgroupSession && $this->canManageSession($user, $session);
    }

    public function canViewFile(User $user, WorkgroupFile $file): bool
    {
        $file->loadMissing('workgroup');
        $workgroup = $file->workgroup;

        return $workgroup instanceof Workgroup && $this->canViewWorkgroup($user, $workgroup);
    }

    public function canViewUpload(User $user, WorkgroupSharedUpload $upload): bool
    {
        $upload->loadMissing('workgroup');
        $workgroup = $upload->workgroup;

        return $workgroup instanceof Workgroup && $this->canViewWorkgroup($user, $workgroup);
    }

    public function canViewEvaluationSubmission(User $user, EvaluationSubmission $submission): bool
    {
        $submission->loadMissing('candidateProduct.session.workgroup');
        $product = $submission->candidateProduct;

        return $product instanceof CandidateProduct
            && $this->canViewCandidateProduct($user, $product);
    }

    public function canManageUpload(User $user, WorkgroupSharedUpload $upload): bool
    {
        $upload->loadMissing('workgroup');
        $workgroup = $upload->workgroup;

        return $workgroup instanceof Workgroup && $this->canManageWorkgroup($user, $workgroup);
    }

    public function requireWorkgroup(User $user, Workgroup $workgroup): void
    {
        abort_unless($this->canViewWorkgroup($user, $workgroup), 404);
    }

    public function requireSession(User $user, WorkgroupSession $session): void
    {
        abort_unless($this->canViewSession($user, $session), 404);
    }

    public function requireCandidateProduct(User $user, CandidateProduct $product): void
    {
        abort_unless($this->canViewCandidateProduct($user, $product), 404);
    }

    public function requireFile(User $user, WorkgroupFile $file): void
    {
        abort_unless($this->canViewFile($user, $file), 404);
    }

    public function requireUpload(User $user, WorkgroupSharedUpload $upload): void
    {
        abort_unless($this->canViewUpload($user, $upload), 404);
    }

    public function requireManageSession(User $user, WorkgroupSession $session): void
    {
        abort_unless($this->canManageSession($user, $session), 404);
    }

    public function requireManageCandidateProduct(User $user, CandidateProduct $product): void
    {
        abort_unless($this->canManageCandidateProduct($user, $product), 404);
    }

    public function requireManageUpload(User $user, WorkgroupSharedUpload $upload): void
    {
        abort_unless($this->canManageUpload($user, $upload), 404);
    }

    /**
     * @param  Builder<Workgroup>  $query
     * @return Builder<Workgroup>
     */
    public function scopeWorkgroups(Builder $query, ?User $user): Builder
    {
        return $this->scopeByWorkgroupColumn($query, $user, 'workgroups.id');
    }

    /**
     * @param  Builder<Workgroup>  $query
     * @return Builder<Workgroup>
     */
    public function scopeManageWorkgroups(Builder $query, ?User $user): Builder
    {
        return $this->scopeByManagedWorkgroupColumn($query, $user, 'workgroups.id');
    }

    /**
     * @param  Builder<WorkgroupSession>  $query
     * @return Builder<WorkgroupSession>
     */
    public function scopeSessions(Builder $query, ?User $user): Builder
    {
        return $this->scopeByWorkgroupColumn($query, $user, 'workgroup_id');
    }

    /**
     * @param  Builder<WorkgroupSession>  $query
     * @return Builder<WorkgroupSession>
     */
    public function scopeManageSessions(Builder $query, ?User $user): Builder
    {
        return $this->scopeByManagedWorkgroupColumn($query, $user, 'workgroup_id');
    }

    /**
     * @template TWorkgroupRecord of WorkgroupFile|WorkgroupMember|WorkgroupSharedUpload
     *
     * @param  Builder<TWorkgroupRecord>  $query
     * @return Builder<TWorkgroupRecord>
     */
    public function scopeWorkgroupRecords(Builder $query, ?User $user): Builder
    {
        return $this->scopeByWorkgroupColumn($query, $user, 'workgroup_id');
    }

    /**
     * @param  Builder<CandidateProduct>  $query
     * @return Builder<CandidateProduct>
     */
    public function scopeCandidateProducts(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobalViewer($user)) {
            return $query;
        }

        return $query->whereHas('session', function (Builder $sessions) use ($user): Builder {
            /** @var Builder<WorkgroupSession> $sessions */
            return $this->scopeSessions($sessions, $user);
        });
    }

    /**
     * @param  Builder<EvaluationSubmission>  $query
     * @return Builder<EvaluationSubmission>
     */
    public function scopeEvaluationSubmissions(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobalViewer($user)) {
            return $query;
        }

        return $query->whereHas('candidateProduct.session', function (Builder $sessions) use ($user): Builder {
            /** @var Builder<WorkgroupSession> $sessions */
            return $this->scopeSessions($sessions, $user);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeByWorkgroupColumn(Builder $query, ?User $user, string $column): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobalViewer($user)) {
            return $query;
        }

        return $query->whereIn($column, $this->activeMembershipsFor($user)->select('workgroup_id'));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeByManagedWorkgroupColumn(Builder $query, ?User $user, string $column): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('super_admin') || $user->can('admin.workgroups.manage')) {
            return $query;
        }

        return $query->whereIn(
            $column,
            $this->activeMembershipsFor($user)
                ->whereIn('role', ['admin', 'facilitator'])
                ->select('workgroup_id'),
        );
    }

    /** @return Builder<WorkgroupMember> */
    private function activeMembershipsFor(User $user): Builder
    {
        return WorkgroupMember::query()
            ->where('user_id', $user->id)
            ->where('is_active', true);
    }
}
