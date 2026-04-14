<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ContactTag;
use App\Models\DirectoryTag;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform-default tag libraries (team_id = null) for both
 * Contact and DirectoryEntry. Tenants see these as starter vocabulary
 * and layer their own team-scoped tags on top.
 *
 * Two separate libraries because the use cases are different:
 *   - Contact tags drive outbound communication routing
 *   - Directory tags drive inbound call / transfer decisions
 */
class ContactTagLibrarySeeder extends Seeder
{
    public function run(): void
    {
        // Contact tags — who do we reach for what
        $contactDefaults = [
            ['slug' => 'primary',     'name' => 'Primary',     'color' => 'primary', 'description' => 'Main point of contact for the account.'],
            ['slug' => 'billing',     'name' => 'Billing',     'color' => 'warning', 'description' => 'Invoices, payments, renewals.'],
            ['slug' => 'technical',   'name' => 'Technical',   'color' => 'info',    'description' => 'Integrations, provisioning, incident notifications.'],
            ['slug' => 'escalation',  'name' => 'Escalation',  'color' => 'danger',  'description' => 'Paged when a customer-impacting issue is unresolved.'],
            ['slug' => 'newsletter',  'name' => 'Newsletter',  'color' => 'gray',    'description' => 'Opted in to product updates and release notes.'],
            ['slug' => 'holiday',     'name' => 'Holiday',     'color' => 'success', 'description' => 'Receives seasonal cards and thank-yous.'],
            ['slug' => 'after-hours', 'name' => 'After Hours', 'color' => 'gray',    'description' => 'Approved for contact outside business hours.'],
        ];

        foreach ($contactDefaults as $row) {
            ContactTag::query()->updateOrCreate(
                ['team_id' => null, 'slug' => $row['slug']],
                $row,
            );
        }

        // Directory tags — how we route when a call comes in
        $directoryDefaults = [
            ['slug' => 'primary',         'name' => 'Primary',         'color' => 'primary', 'description' => 'First-line contact for this organization.'],
            ['slug' => 'backup',          'name' => 'Backup',          'color' => 'gray',    'description' => 'Try if the primary is unavailable.'],
            ['slug' => 'emergency',       'name' => 'Emergency',       'color' => 'danger',  'description' => 'Contact for urgent/after-hours incidents.'],
            ['slug' => 'after-hours',     'name' => 'After Hours',     'color' => 'warning', 'description' => 'Reachable outside normal business hours.'],
            ['slug' => 'no-calls',        'name' => 'Do Not Call',     'color' => 'danger',  'description' => 'Explicitly opted out of phone contact.'],
            ['slug' => 'spanish-speaker', 'name' => 'Spanish',         'color' => 'info',    'description' => 'Prefers Spanish-language operator or agent.'],
            ['slug' => 'vip',             'name' => 'VIP',             'color' => 'success', 'description' => 'Priority routing — handle immediately.'],
        ];

        foreach ($directoryDefaults as $row) {
            DirectoryTag::query()->updateOrCreate(
                ['team_id' => null, 'slug' => $row['slug']],
                $row,
            );
        }
    }
}
