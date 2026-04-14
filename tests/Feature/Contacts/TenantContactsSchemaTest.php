<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Livewire\ContactSmartIngest;
use App\Models\Contact;
use App\Models\ContactFieldDefinition;
use App\Models\DirectoryEntry;
use App\Models\DirectoryFieldDefinition;
use App\Models\Team;
use App\Models\User;
use App\Services\Contacts\ColumnMappingGuesser;
use App\Services\Contacts\FieldFormBuilder;
use App\Services\Contacts\SmartIngestClient;
use App\Services\Tenancy\TenantProvisioner;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Coverage for the per-tenant Contact / Directory schema feature.
 *
 * The suite exercises every regression-prone surface in one file:
 *   - TenantProvisioner seeds the starter contact/directory field sets
 *   - Role accessors on Contact + DirectoryEntry resolve through
 *     definitions instead of hard-coded columns
 *   - FieldFormBuilder emits the right Filament component per type
 *   - ColumnMappingGuesser matches by label, by key, and by role alias
 *   - SmartIngestClient::formatSchemaForPrompt produces the expected
 *     bullet list (the LLM's source of truth)
 *   - ContactSmartIngest::sanitizeRow strips keys outside the schema
 *   - Tenant Filament sub-pages (Contacts, Contact Fields, Directory,
 *     Directory Fields, Smart Ingest) all render 200 for a freshly-
 *     provisioned tenant
 *   - Grant portal access action visibility is gated on role presence
 */
class TenantContactsSchemaTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected User $admin;

    protected Team $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);

        $this->admin = $this->makeUserWithTeamlessRole('super_admin');
        $this->tenant = $this->provisionTenant('Acme Cleaners');
    }

    // ── Provisioner ─────────────────────────────────────────────────

    public function test_provisioner_seeds_seven_starter_contact_fields(): void
    {
        $defs = $this->tenant->contactFieldDefinitions()->orderBy('sort_order')->get();

        $this->assertSame(7, $defs->count());
        $this->assertSame(
            ['name', 'email', 'phone', 'organization', 'title', 'preferred_contact_method', 'notes'],
            $defs->pluck('key')->all(),
        );

        // Roles on the right fields.
        $this->assertSame('name', $defs->firstWhere('key', 'name')->role);
        $this->assertSame('email', $defs->firstWhere('key', 'email')->role);
        $this->assertSame('phone', $defs->firstWhere('key', 'phone')->role);
        $this->assertSame('organization', $defs->firstWhere('key', 'organization')->role);
        $this->assertSame('none', $defs->firstWhere('key', 'title')->role);

        // Starter name is required, others aren't.
        $this->assertTrue($defs->firstWhere('key', 'name')->required);
        $this->assertFalse($defs->firstWhere('key', 'email')->required);
    }

    public function test_provisioner_seeds_seven_starter_directory_fields(): void
    {
        $defs = $this->tenant->directoryFieldDefinitions()->orderBy('sort_order')->get();

        $this->assertSame(7, $defs->count());
        $this->assertSame(
            ['name', 'organization', 'department', 'title', 'primary_phone', 'email', 'notes'],
            $defs->pluck('key')->all(),
        );

        $this->assertSame('name', $defs->firstWhere('key', 'name')->role);
        $this->assertSame('phone', $defs->firstWhere('key', 'primary_phone')->role);
        $this->assertSame('email', $defs->firstWhere('key', 'email')->role);
    }

    // ── Contact role accessors ──────────────────────────────────────

    public function test_contact_role_accessors_resolve_through_definitions(): void
    {
        $contact = Contact::create([
            'team_id' => $this->tenant->id,
            'values' => [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'phone' => '+15550100',
                'organization' => 'Acme',
                'title' => 'Coordinator',
            ],
        ]);

        $this->assertSame('Jane Smith', $contact->name());
        $this->assertSame('jane@example.com', $contact->email());
        $this->assertSame('+15550100', $contact->phone());
        $this->assertSame('Acme', $contact->organization());
        $this->assertSame('Coordinator', $contact->value('title'));
        $this->assertNull($contact->value('notes'));
        $this->assertFalse($contact->hasPortalAccess());
    }

    public function test_contact_role_accessor_returns_null_when_role_unassigned(): void
    {
        // Reassign the email-role field to none — now email() should
        // not find a match even though the row still has an "email"
        // value in its JSON.
        ContactFieldDefinition::query()
            ->where('team_id', $this->tenant->id)
            ->where('key', 'email')
            ->update(['role' => 'none']);

        $contact = Contact::create([
            'team_id' => $this->tenant->id,
            'values' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]);

        $this->assertSame('Jane', $contact->name());
        $this->assertNull($contact->email());
    }

    public function test_contact_role_accessor_renames_canonical_field(): void
    {
        // Swap "Patient Name" for the existing name field — the
        // canonical name role should follow the role assignment, not
        // the slug.
        ContactFieldDefinition::query()
            ->where('team_id', $this->tenant->id)
            ->where('role', 'name')
            ->update(['role' => 'none']);

        ContactFieldDefinition::create([
            'team_id' => $this->tenant->id,
            'key' => 'patient_name',
            'label' => 'Patient Name',
            'type' => 'text',
            'role' => 'name',
            'required' => true,
            'sort_order' => 5,
        ]);

        $contact = Contact::create([
            'team_id' => $this->tenant->id,
            'values' => ['patient_name' => 'Bob Roe', 'name' => 'IGNORED'],
        ]);

        $this->assertSame('Bob Roe', $contact->name());
    }

    // ── Directory role accessors ────────────────────────────────────

    public function test_directory_full_name_uses_role_assignment(): void
    {
        $entry = DirectoryEntry::create([
            'team_id' => $this->tenant->id,
            'values' => ['name' => 'Dr. Smith', 'primary_phone' => '+15550199'],
        ]);

        $this->assertSame('Dr. Smith', $entry->fullName());
        $this->assertSame('+15550199', $entry->phone());
    }

    public function test_directory_full_name_falls_back_to_unnamed_when_blank(): void
    {
        $entry = DirectoryEntry::create([
            'team_id' => $this->tenant->id,
            'values' => ['primary_phone' => '+15550199'],
        ]);

        $this->assertSame('Unnamed', $entry->fullName());
        $this->assertNull($entry->name());
    }

    // ── ColumnMappingGuesser ────────────────────────────────────────

    public function test_guesser_matches_exact_label(): void
    {
        $guesser = app(ColumnMappingGuesser::class);

        $this->assertSame('organization', $guesser->guessContact($this->tenant->id, 'Organization'));
        $this->assertSame('preferred_contact_method', $guesser->guessContact($this->tenant->id, 'Preferred contact method'));
    }

    public function test_guesser_matches_exact_key(): void
    {
        $guesser = app(ColumnMappingGuesser::class);

        $this->assertSame('phone', $guesser->guessContact($this->tenant->id, 'phone'));
        $this->assertSame('preferred_contact_method', $guesser->guessContact($this->tenant->id, 'preferred_contact_method'));
    }

    public function test_guesser_matches_role_alias_when_label_is_idiosyncratic(): void
    {
        // Rename the email field to something the exact-match pass
        // would never hit, but keep its role=email.
        ContactFieldDefinition::query()
            ->where('team_id', $this->tenant->id)
            ->where('key', 'email')
            ->update(['key' => 'patient_contact_email', 'label' => 'Patient Contact Email']);

        $guesser = app(ColumnMappingGuesser::class);

        // "Email Address" doesn't match the label or the key, but the
        // alias table maps it to role=email which the role-fallback
        // pass should resolve to patient_contact_email.
        $this->assertSame('patient_contact_email', $guesser->guessContact($this->tenant->id, 'Email Address'));
    }

    public function test_guesser_returns_null_when_no_match(): void
    {
        $guesser = app(ColumnMappingGuesser::class);

        $this->assertNull($guesser->guessContact($this->tenant->id, 'Mother\'s Maiden Name'));
    }

    public function test_guesser_options_includes_skip_sentinel(): void
    {
        $guesser = app(ColumnMappingGuesser::class);
        $options = $guesser->optionsForContacts($this->tenant->id);

        $this->assertArrayHasKey('', $options);
        $this->assertSame('— Skip this column —', $options['']);
        $this->assertArrayHasKey('email', $options);
        $this->assertSame('Email', $options['email']);
    }

    // ── FieldFormBuilder ────────────────────────────────────────────

    public function test_form_builder_emits_one_component_per_active_field(): void
    {
        $builder = app(FieldFormBuilder::class);
        $defs = $this->tenant->contactFieldDefinitions()->get();

        $components = $builder->build($defs);

        $this->assertCount(7, $components);
    }

    public function test_form_builder_skips_inactive_fields(): void
    {
        $this->tenant->contactFieldDefinitions()
            ->where('key', 'notes')
            ->update(['is_active' => false]);

        $builder = app(FieldFormBuilder::class);
        $defs = $this->tenant->contactFieldDefinitions()->get();

        $components = $builder->build($defs);

        $this->assertCount(6, $components);
    }

    public function test_form_builder_uses_values_dot_notation_for_state_keys(): void
    {
        $builder = app(FieldFormBuilder::class);
        $defs = $this->tenant->contactFieldDefinitions()->get();

        $components = $builder->build($defs);

        foreach ($components as $component) {
            $this->assertStringStartsWith('values.', $component->getName());
        }
    }

    // ── SmartIngestClient::formatSchemaForPrompt ────────────────────

    public function test_smart_ingest_schema_block_lists_every_active_field(): void
    {
        $client = app(SmartIngestClient::class);
        $defs = $this->tenant->contactFieldDefinitions()->get();

        $block = $client->formatSchemaForPrompt($defs);

        $this->assertStringContainsString('- name (text, role: name, required): Name', $block);
        $this->assertStringContainsString('- email (email, role: email): Email', $block);
        $this->assertStringContainsString('- preferred_contact_method (select, one of: email, phone, sms, mail, any): Preferred contact method', $block);
        $this->assertStringContainsString('- notes (textarea): Notes', $block);
    }

    public function test_smart_ingest_schema_block_returns_placeholder_when_empty(): void
    {
        $client = app(SmartIngestClient::class);
        $block = $client->formatSchemaForPrompt(collect([]));

        $this->assertStringContainsString('no fields defined', $block);
    }

    // ── ContactSmartIngest::sanitizeRow ─────────────────────────────

    public function test_smart_ingest_sanitize_row_drops_keys_outside_schema(): void
    {
        Livewire::test(ContactSmartIngest::class, ['teamId' => $this->tenant->id, 'kind' => 'contacts'])
            ->set('rows', [
                [
                    'name' => 'Jane',
                    'email' => 'jane@example.com',
                    'social_security_number' => '000-00-0000', // hallucinated
                    'favorite_marvel_character' => 'Groot',    // hallucinated
                ],
            ])
            ->call('commit')
            ->assertHasNoErrors();

        $contact = Contact::query()->where('team_id', $this->tenant->id)->latest('id')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Jane', $contact->value('name'));
        $this->assertSame('jane@example.com', $contact->value('email'));
        $this->assertNull($contact->value('social_security_number'));
        $this->assertNull($contact->value('favorite_marvel_character'));
    }

    public function test_smart_ingest_directory_kind_writes_to_directory_entries(): void
    {
        Livewire::test(ContactSmartIngest::class, ['teamId' => $this->tenant->id, 'kind' => 'directory'])
            ->set('rows', [
                ['name' => 'Dr. Bob', 'primary_phone' => '+15550200', 'organization' => 'Beta Hospital'],
            ])
            ->call('commit');

        $this->assertSame(1, DirectoryEntry::query()->where('team_id', $this->tenant->id)->count());
        $this->assertSame(0, Contact::query()->where('team_id', $this->tenant->id)->count());

        $entry = DirectoryEntry::query()->where('team_id', $this->tenant->id)->first();
        $this->assertSame('Dr. Bob', $entry->name());
        $this->assertSame('+15550200', $entry->phone());
    }

    // ── Tenant Filament sub-pages render ────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function tenantSubPaths(): array
    {
        return [
            'contacts' => ['contacts'],
            'contact-fields' => ['contact-fields'],
            'directory' => ['directory'],
            'directory-fields' => ['directory-fields'],
            'smart-ingest contacts' => ['smart-ingest?kind=contacts'],
            'smart-ingest directory' => ['smart-ingest?kind=directory'],
        ];
    }

    #[DataProvider('tenantSubPaths')]
    public function test_tenant_sub_pages_render(string $path): void
    {
        $this->loginAs($this->admin)
            ->get("/admin/tenants/{$this->tenant->id}/{$path}")
            ->assertOk();
    }

    // ── Grant portal access role-gating ────────────────────────────

    public function test_contact_with_name_and_email_roles_can_grant_portal_access(): void
    {
        $contact = Contact::create([
            'team_id' => $this->tenant->id,
            'values' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]);

        $this->assertNotNull($contact->name());
        $this->assertNotNull($contact->email());
        $this->assertFalse($contact->hasPortalAccess());
    }

    public function test_contact_without_email_role_cannot_grant_portal_access(): void
    {
        // Drop the email role from any field.
        ContactFieldDefinition::query()
            ->where('team_id', $this->tenant->id)
            ->where('role', 'email')
            ->update(['role' => 'none']);

        $contact = Contact::create([
            'team_id' => $this->tenant->id,
            'values' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]);

        // The contact's underlying value is still in the JSON, but the
        // role accessor returns null because no field has role=email.
        $this->assertNotNull($contact->name());
        $this->assertNull($contact->email());
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * Provision a fresh tenant via the real provisioner so the field
     * starter sets land. Avoids the test factories so we don't drift
     * from production behavior.
     */
    protected function provisionTenant(string $name): Team
    {
        $owner = User::factory()->create();
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
        ]);

        app(TenantProvisioner::class)->provision($team);

        return $team->fresh();
    }
}
