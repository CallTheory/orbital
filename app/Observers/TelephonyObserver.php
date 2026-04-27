<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Services\Telephony\Realtime\EndpointSyncer;
use App\Services\Telephony\Realtime\QueueMemberSyncer;
use App\Services\Telephony\Realtime\QueueSyncer;
use App\Services\Telephony\Realtime\TrunkSyncer;
use Illuminate\Database\Eloquent\Model;

/**
 * Single observer attached to SipTrunk, Extension, CallQueue, and
 * RoutingRule. Dispatches the right action for each model type:
 *
 *   - **Extension**  → EndpointSyncer writes ps_endpoints / ps_auths
 *                       / ps_aors. **Zero AMI reload.**
 *   - **SipTrunk**   → TrunkSyncer writes the same plus
 *                       ps_endpoint_id_ips. **Zero AMI reload.**
 *   - **CallQueue**  → QueueSyncer writes the queues row,
 *                       QueueMemberSyncer recomputes the matching
 *                       queue_members rows. **Zero AMI reload.**
 *   - **RoutingRule** → dispatches RegenerateTelephonyConfig with
 *                        the rule's team_id; the job writes only
 *                        that client's dialplan file plus the
 *                        from-trunk dispatcher and triggers
 *                        `dialplan reload` (NOT `core reload`).
 *
 * The 90% reduction in reload churn comes from the first three
 * paths: at 1000 clients, an Extension/Queue/Trunk edit no longer
 * causes Asterisk to re-parse the whole world.
 */
class TelephonyObserver
{
    public function __construct(
        protected EndpointSyncer $endpointSyncer,
        protected TrunkSyncer $trunkSyncer,
        protected QueueSyncer $queueSyncer,
        protected QueueMemberSyncer $queueMemberSyncer,
    ) {}

    public function created(Model $model): void
    {
        $this->dispatch($model, 'sync');
    }

    public function updated(Model $model): void
    {
        $this->dispatch($model, 'sync');
    }

    public function deleted(Model $model): void
    {
        $this->dispatch($model, 'delete');
    }

    /**
     * Routes the model + verb to the right syncer or queues the
     * dialplan job. `$verb` is either 'sync' or 'delete'.
     */
    protected function dispatch(Model $model, string $verb): void
    {
        match (true) {
            $model instanceof Extension => $verb === 'sync'
                ? $this->endpointSyncer->sync($model)
                : $this->endpointSyncer->delete($model),

            $model instanceof SipTrunk => $verb === 'sync'
                ? $this->trunkSyncer->sync($model)
                : $this->trunkSyncer->delete($model),

            $model instanceof CallQueue => $this->onQueueChange($model, $verb),

            $model instanceof RoutingRule => $this->onRoutingRuleChange($model),

            default => null,
        };
    }

    protected function onQueueChange(CallQueue $queue, string $verb): void
    {
        if ($verb === 'delete') {
            $this->queueSyncer->delete($queue);

            return;
        }

        $this->queueSyncer->sync($queue);
        $this->queueMemberSyncer->syncForQueue($queue);
    }

    protected function onRoutingRuleChange(RoutingRule $rule): void
    {
        // Routing rules still touch the dialplan, so we go through
        // the queue job (deduped + per-client). The job calls
        // writeDialplanForTenant() and reloadDialplan() — never
        // a full core reload.
        $teamId = $rule->team_id ?? null;
        RegenerateTelephonyConfig::dispatch($teamId);
    }
}
