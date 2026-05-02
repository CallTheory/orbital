<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapRegistry;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests the pure-PHP BootstrapRegistry layer: registration,
 * lookup, status aggregation, and runAll's halt-on-required /
 * skip-on-optional contract.
 *
 * Uses fake in-memory Bootstrappers so none of the real service
 * probes fire — this test runs without any Docker services up.
 */
class BootstrapRegistryTest extends TestCase
{
    public function test_register_and_get_bootstrapper(): void
    {
        $registry = new BootstrapRegistry;
        $fake = $this->fake('demo', BootstrapStatus::Installed, 'ok');
        $registry->register($fake);

        $this->assertSame($fake, $registry->get('demo'));
        $this->assertCount(1, $registry->all());
    }

    public function test_status_all_returns_report_per_bootstrapper(): void
    {
        $registry = new BootstrapRegistry;
        $registry
            ->register($this->fake('a', BootstrapStatus::Installed, 'ok a'))
            ->register($this->fake('b', BootstrapStatus::Partial, 'ok b'));

        $reports = $registry->statusAll();

        $this->assertArrayHasKey('a', $reports);
        $this->assertArrayHasKey('b', $reports);
        $this->assertSame(BootstrapStatus::Installed, $reports['a']->status);
        $this->assertSame(BootstrapStatus::Partial, $reports['b']->status);
    }

    public function test_run_all_skips_optional_failures_but_reraises_required_failures(): void
    {
        $registry = new BootstrapRegistry;
        $registry->register($this->failing('required', optional: false));

        $this->expectException(RuntimeException::class);
        $registry->runAll();
    }

    public function test_run_all_swallows_optional_failures(): void
    {
        $registry = new BootstrapRegistry;
        $registry
            ->register($this->failing('optional-broken', optional: true))
            ->register($this->fake('fine', BootstrapStatus::Installed, 'ok'));

        $reports = $registry->runAll();

        $this->assertCount(2, $reports);
        $this->assertSame(BootstrapStatus::Error, $reports['optional-broken']->status);
        $this->assertSame(BootstrapStatus::Installed, $reports['fine']->status);
    }

    // ---- fakes ------------------------------------------------------

    private function fake(string $key, BootstrapStatus $status, string $message): Bootstrapper
    {
        return new class($key, $status, $message) implements Bootstrapper
        {
            public function __construct(
                private string $key,
                private BootstrapStatus $status,
                private string $message,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function name(): string
            {
                return 'Fake '.$this->key;
            }

            public function description(): string
            {
                return 'test';
            }

            public function icon(): string
            {
                return 'heroicon-o-bug-ant';
            }

            public function isOptional(): bool
            {
                return false;
            }

            public function status(): BootstrapReport
            {
                return new BootstrapReport($this->status, $this->message);
            }

            public function install(): BootstrapReport
            {
                return new BootstrapReport($this->status, $this->message);
            }
        };
    }

    private function failing(string $key, bool $optional): Bootstrapper
    {
        return new class($key, $optional) implements Bootstrapper
        {
            public function __construct(
                private string $key,
                private bool $optional,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function name(): string
            {
                return 'Failing '.$this->key;
            }

            public function description(): string
            {
                return 'test';
            }

            public function icon(): string
            {
                return 'heroicon-o-x-circle';
            }

            public function isOptional(): bool
            {
                return $this->optional;
            }

            public function status(): BootstrapReport
            {
                return new BootstrapReport(BootstrapStatus::Error, 'fake failure');
            }

            public function install(): BootstrapReport
            {
                throw new RuntimeException('fake install failure');
            }
        };
    }
}
