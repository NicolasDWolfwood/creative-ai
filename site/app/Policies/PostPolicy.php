<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function restore(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function preview(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function manageConnections(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function markReady(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function revertToDraft(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function schedule(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function publishNow(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function cancelSchedule(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    public function unpublish(User $user, Post $post): bool
    {
        return $this->isAdministrator($user);
    }

    protected function isAdministrator(User $user): bool
    {
        return (bool) $user->is_admin;
    }
}
