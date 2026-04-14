<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform skill vocabulary. Skills are platform-only —
 * tenants don't author them — so this seeder runs once per fresh
 * install and gives the operator/admin UI a useful starter list.
 *
 * Add to this list rather than editing existing slugs in place;
 * QueueMemberSyncer references skills by id (via the pivot tables)
 * so renaming a slug doesn't break anything but the operator UI
 * drift would be confusing.
 */
class SkillCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            ['slug' => 'spanish',         'name' => 'Spanish',                 'category' => 'language',     'description' => 'Operator can hold a call entirely in Spanish.'],
            ['slug' => 'french',          'name' => 'French',                  'category' => 'language',     'description' => 'Operator can hold a call entirely in French.'],
            ['slug' => 'after-hours',     'name' => 'After Hours',             'category' => 'availability', 'description' => 'Reachable outside normal business hours (evenings, weekends).'],
            ['slug' => 'on-call',         'name' => 'On-Call Escalation',      'category' => 'availability', 'description' => 'Carries the on-call rotation pager — receives priority routing.'],
            ['slug' => 'medical-intake',  'name' => 'Medical Intake',          'category' => 'specialty',    'description' => 'Trained for HIPAA-aware patient intake calls (vitals, scheduling).'],
            ['slug' => 'legal-intake',    'name' => 'Legal Intake',            'category' => 'specialty',    'description' => 'Trained for first-call legal intake including conflict checks.'],
            ['slug' => 'billing',         'name' => 'Billing & Payments',      'category' => 'specialty',    'description' => 'Comfortable handling billing questions, payment collection, refunds.'],
            ['slug' => 'tier-1-support',  'name' => 'Tier 1 Support',          'category' => 'specialty',    'description' => 'General first-line support — knows the platform basics.'],
            ['slug' => 'tier-2-support',  'name' => 'Tier 2 Support',          'category' => 'specialty',    'description' => 'Escalation target for issues T1 can\'t resolve.'],
            ['slug' => 'vip-tier',        'name' => 'VIP Tier',                'category' => 'tier',         'description' => 'Cleared to answer enterprise-tier tenant queues.'],
            ['slug' => 'compliance',      'name' => 'Compliance Trained',      'category' => 'compliance',   'description' => 'Has completed annual compliance / privacy training.'],
        ];

        foreach ($skills as $row) {
            Skill::query()->updateOrCreate(
                ['slug' => $row['slug']],
                $row + ['is_active' => true],
            );
        }
    }
}
