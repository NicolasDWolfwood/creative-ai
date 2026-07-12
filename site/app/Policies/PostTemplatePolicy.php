<?php

namespace App\Policies;

use App\Models\PostTemplate;
use App\Models\User;

class PostTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, PostTemplate $postTemplate): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, PostTemplate $postTemplate): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, PostTemplate $postTemplate): bool
    {
        return $this->isAdministrator($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    protected function isAdministrator(User $user): bool
    {
        return (bool) $user->is_admin;
    }
}
