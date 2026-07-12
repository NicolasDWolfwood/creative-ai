<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_database_administrators_can_access_the_admin_panel(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $panel = Panel::make()->id('admin');

        $this->assertTrue($admin->canAccessPanel($panel));
        $this->assertFalse($user->canAccessPanel($panel));
        $this->assertFalse($admin->canAccessPanel(Panel::make()->id('another-panel')));
    }

    public function test_admin_panel_requires_recoverable_app_mfa(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->isMultiFactorAuthenticationRequired());
        $this->assertCount(1, $panel->getMultiFactorAuthenticationProviders());
        $this->assertTrue($panel->hasProfile());
    }

    public function test_only_authorized_administrators_see_the_public_admin_switch(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $adminUrl = Filament::getPanel('admin')->getUrl();
        $adminLink = '<a href="'.$adminUrl.'">Admin</a>';

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee($adminLink, escape: false);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee($adminLink, escape: false);

        $response = $this->actingAs($admin)->get(route('home'));

        $response->assertOk()->assertSee($adminLink, escape: false);
        $this->assertSame(2, substr_count($response->getContent(), $adminLink));
    }

    public function test_an_authenticated_non_administrator_cannot_open_the_admin_url(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_user_menu_has_a_switch_back_to_the_public_site(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $viewSite = Filament::getPanel('admin')->getUserMenuItems()['viewSite'];

        $this->assertSame('View site', $viewSite->getLabel());
        $this->assertSame(route('home'), $viewSite->getUrl());
    }

    public function test_admin_theme_elevates_the_complete_open_select_stack_without_elevating_the_modal_footer(): void
    {
        $stylesheet = file_get_contents(resource_path('css/filament/admin.css'));

        $this->assertIsString($stylesheet);
        $this->assertMatchesRegularExpression(
            "/\\.fi-grid-col:has\\(\\.fi-select-input-btn\\[aria-expanded='true'\\]\\),[^}]*position: relative;[^}]*z-index: 60;/s",
            $stylesheet,
        );
        $this->assertMatchesRegularExpression(
            "/\\.fi-fo-select-wrp:has\\(\\.fi-select-input-btn\\[aria-expanded='true'\\]\\) \\{[^}]*position: relative;[^}]*z-index: 60;/s",
            $stylesheet,
        );
        $this->assertMatchesRegularExpression(
            "/\\.fi-section:has\\(\\.fi-select-input-btn\\[aria-expanded='true'\\]\\) \\{[^}]*position: relative;[^}]*z-index: 50;/s",
            $stylesheet,
        );
        $this->assertMatchesRegularExpression(
            "/\\.fi-fo-select-wrp:has\\(\\.fi-select-input-btn\\[aria-expanded='true'\\]\\) \\.fi-dropdown-panel \\{[^}]*z-index: 60;[^}]*background: #11141a !important;[^}]*backdrop-filter: none;/s",
            $stylesheet,
        );
        $this->assertStringNotContainsString(
            ".fi-modal-content:has(.fi-select-input-btn[aria-expanded='true'])",
            $stylesheet,
        );
        $this->assertStringNotContainsString(
            ".fi-modal.fi-modal-has-sticky-footer:has(.fi-select-input-btn[aria-expanded='true'])",
            $stylesheet,
        );
    }

    public function test_create_command_creates_a_verified_administrator(): void
    {
        $this->artisan('creative-ai:admin:create', [
            'email' => '  ADMIN@Example.Test ',
            '--name' => 'Site Administrator',
            '--generate-password' => true,
        ])->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame('Site Administrator', $admin->name);
        $this->assertNotSame('', $admin->password);
    }

    public function test_promoting_an_existing_user_preserves_their_password(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.test',
            'password' => 'existing-password',
        ]);
        $passwordHash = $user->password;

        $this->artisan('creative-ai:admin:create', ['email' => 'MEMBER@example.test'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->is_admin);
        $this->assertSame($passwordHash, $user->password);
    }

    public function test_reset_command_changes_an_administrator_password(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'old-password-value',
            'remember_token' => 'old-token',
        ]);

        $this->artisan('creative-ai:admin:reset-password', [
            'email' => $admin->email,
            '--generate-password' => true,
        ])->assertSuccessful();

        $admin->refresh();
        $this->assertTrue($admin->is_admin);
        $this->assertFalse(Hash::check('old-password-value', $admin->password));
        $this->assertNotSame('old-token', $admin->remember_token);
    }

    public function test_revoke_command_protects_the_final_administrator(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);

        $this->artisan('creative-ai:admin:revoke', ['email' => $admin->email])
            ->assertFailed();
        $this->assertTrue($admin->fresh()->is_admin);

        $this->artisan('creative-ai:admin:revoke', [
            'email' => $admin->email,
            '--allow-no-admin' => true,
        ])->assertSuccessful();
        $this->assertFalse($admin->fresh()->is_admin);
    }

    public function test_revoke_command_rechecks_final_admin_protection_after_each_change(): void
    {
        $first = User::factory()->admin()->create(['email' => 'first@example.test']);
        $second = User::factory()->admin()->create(['email' => 'second@example.test']);

        $this->artisan('creative-ai:admin:revoke', ['email' => $first->email])
            ->assertSuccessful();
        $this->artisan('creative-ai:admin:revoke', ['email' => $second->email])
            ->assertFailed();

        $this->assertFalse($first->fresh()->is_admin);
        $this->assertTrue($second->fresh()->is_admin);
    }

    public function test_admin_commands_reject_invalid_email_addresses(): void
    {
        $this->artisan('creative-ai:admin:create', [
            'email' => 'not-an-email',
            '--generate-password' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
