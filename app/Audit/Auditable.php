<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes created/updated/deleted events to the append-only audit log.
 * Encrypted / sensitive attributes are recorded as changed but never in clear text.
 *
 * Models may define:
 *   protected array $auditRedact  = [...]  attributes logged as "[redacted]"
 *   protected array $auditExclude = [...]  attributes never logged
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAudit('created', [], $model->auditPayload($model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = $model->auditPayload($model->getChanges());
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = $model->auditPayload(array_intersect_key($model->getOriginal(), $changes));
            $model->writeAudit('updated', $old, $changes);
        });

        static::deleted(fn (Model $model) => $model->writeAudit('deleted', $model->auditPayload($model->getOriginal()), []));
    }

    protected function writeAudit(string $event, array $old, array $new): void
    {
        app(AuditLogger::class)->record($event, $this, $old, $new);
    }

    protected function auditPayload(array $attributes): array
    {
        $exclude = array_merge(['password', 'portal_password', 'remember_token', 'created_at', 'updated_at',
            'portal_invite_token_hash', 'phone_index', 'email_index', 'gateway_credentials'], $this->auditExclude ?? []);
        $redact = array_merge(array_keys(array_filter($this->getCasts(), fn ($cast) => str_starts_with((string) $cast, 'encrypted'))), $this->auditRedact ?? []);

        $payload = [];
        foreach ($attributes as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $payload[$key] = in_array($key, $redact, true) ? '[redacted]' : $value;
        }

        return $payload;
    }
}
