<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;

class PostAiRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, PostAiRun $run): bool
    {
        return $this->isAdministrator($user);
    }

    public function request(User $user, Post $post): bool
    {
        return $this->isAdministrator($user) && ! $post->trashed();
    }

    public function cancel(User $user, PostAiRun $run): bool
    {
        return $this->canManage($user, $run);
    }

    public function prioritize(User $user, PostAiRun $run): bool
    {
        return $this->canManage($user, $run);
    }

    public function retry(User $user, PostAiRun $run): bool
    {
        return $this->canManage($user, $run);
    }

    public function dismiss(User $user, PostAiRun $run): bool
    {
        return $this->canManage($user, $run);
    }

    public function recover(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    private function canManage(User $user, PostAiRun $run): bool
    {
        $post = $run->post;

        return $this->isAdministrator($user)
            && $post !== null
            && ! $post->trashed();
    }

    private function isAdministrator(User $user): bool
    {
        return (bool) $user->is_admin;
    }
}
