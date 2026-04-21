<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Repository;

use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

final class IpPoolRepository
{
    private const TABLE = 'mod_atomfleetproxmox_ip_pool';

    public function ensureSchema(): void
    {
        if (Capsule::schema()->hasTable(self::TABLE)) {
            return;
        }

        Capsule::schema()->create(self::TABLE, static function (Blueprint $table) {
            $table->increments('id');
            $table->integer('server_id')->unsigned();
            $table->string('pool_name', 64)->default('');
            $table->string('address', 45);
            $table->unsignedSmallInteger('prefix')->default(24);
            $table->string('gateway', 45)->nullable();
            $table->string('bridge', 64)->default('');
            $table->integer('service_id')->unsigned()->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(array('server_id', 'address'), 'afp_ip_pool_server_address_unique');
            $table->index(array('server_id', 'service_id'), 'afp_ip_pool_server_service_idx');
            $table->index(array('server_id', 'pool_name', 'bridge'), 'afp_ip_pool_match_idx');
        });
    }

    public function listEntries(int $serverId): array
    {
        $this->ensureSchema();

        if ($serverId <= 0) {
            return array();
        }

        $rows = Capsule::table(self::TABLE . ' as pool')
            ->leftJoin('tblhosting as hosting', 'hosting.id', '=', 'pool.service_id')
            ->leftJoin('tblproducts as product', 'product.id', '=', 'hosting.packageid')
            ->where('pool.server_id', '=', $serverId)
            ->orderBy('pool.pool_name', 'asc')
            ->orderBy('pool.bridge', 'asc')
            ->orderBy('pool.address', 'asc')
            ->get(array(
                'pool.id',
                'pool.server_id',
                'pool.pool_name',
                'pool.address',
                'pool.prefix',
                'pool.gateway',
                'pool.bridge',
                'pool.service_id',
                'hosting.domainstatus as service_status',
                'hosting.domain as service_domain',
                'product.name as product_name',
            ));

        $entries = array();

        foreach ($rows as $row) {
            $entry = $this->normalizeRow($row);
            $entry['allocated'] = !empty($entry['service_id']);
            $entry['serviceLabel'] = '';

            if (!empty($entry['service_id'])) {
                $parts = array('Service #' . (int) $entry['service_id']);

                if (!empty($entry['product_name'])) {
                    $parts[] = (string) $entry['product_name'];
                }

                if (!empty($entry['service_domain'])) {
                    $parts[] = (string) $entry['service_domain'];
                }

                $entry['serviceLabel'] = implode(' / ', $parts);
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    public function importEntries(int $serverId, array $entries): array
    {
        $this->ensureSchema();

        if ($serverId <= 0) {
            throw new ModuleException('A valid WHMCS server is required before importing IP pool entries.');
        }

        $summary = array(
            'added' => 0,
            'updated' => 0,
            'invalid' => 0,
            'skipped' => 0,
        );

        foreach ($entries as $entry) {
            $address = trim((string) ($entry['address'] ?? ''));
            $prefix = (int) ($entry['prefix'] ?? 0);

            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 1 || $prefix > 32) {
                $summary['invalid']++;
                continue;
            }

            $payload = array(
                'server_id' => $serverId,
                'pool_name' => $this->normalizePoolName((string) ($entry['pool_name'] ?? '')),
                'address' => $address,
                'prefix' => $prefix,
                'gateway' => $this->normalizeNullableString((string) ($entry['gateway'] ?? '')),
                'bridge' => $this->normalizeBridge((string) ($entry['bridge'] ?? '')),
                'updated_at' => $this->now(),
            );

            $existing = Capsule::table(self::TABLE)
                ->where('server_id', '=', $serverId)
                ->where('address', '=', $address)
                ->first();

            if ($existing) {
                $existingRow = $this->normalizeRow($existing);
                $changed = false;

                foreach (array('pool_name', 'prefix', 'gateway', 'bridge') as $key) {
                    if ((string) ($existingRow[$key] ?? '') !== (string) $payload[$key]) {
                        $changed = true;
                        break;
                    }
                }

                if (!$changed) {
                    $summary['skipped']++;
                    continue;
                }

                Capsule::table(self::TABLE)
                    ->where('id', '=', (int) $existingRow['id'])
                    ->update($payload);

                $summary['updated']++;
                continue;
            }

            $payload['created_at'] = $payload['updated_at'];
            Capsule::table(self::TABLE)->insert($payload);
            $summary['added']++;
        }

        return $summary;
    }

    public function allocateForService(int $serverId, int $serviceId, string $poolName = '', string $bridge = ''): array
    {
        $this->ensureSchema();

        if ($serverId <= 0 || $serviceId <= 0) {
            throw new ModuleException('Valid WHMCS server/service identifiers are required for IP allocation.');
        }

        return Capsule::connection()->transaction(function () use ($serverId, $serviceId, $poolName, $bridge) {
            $existing = Capsule::table(self::TABLE)
                ->where('server_id', '=', $serverId)
                ->where('service_id', '=', $serviceId)
                ->lockForUpdate()
                ->orderBy('id', 'asc')
                ->first();

            if ($existing) {
                return $this->normalizeRow($existing);
            }

            foreach ($this->availableCandidateQueries($serverId, $poolName, $bridge) as $query) {
                $row = $query->lockForUpdate()->orderBy('id', 'asc')->first();

                if (!$row) {
                    continue;
                }

                $normalized = $this->normalizeRow($row);

                Capsule::table(self::TABLE)
                    ->where('id', '=', (int) $normalized['id'])
                    ->update(array(
                        'service_id' => $serviceId,
                        'updated_at' => $this->now(),
                    ));

                $normalized['service_id'] = $serviceId;

                return $normalized;
            }

            $scope = trim($poolName) !== '' ? ' pool "' . trim($poolName) . '"' : ' configured pool';
            $bridgeHint = trim($bridge) !== '' ? ' on bridge "' . trim($bridge) . '"' : '';

            throw new ModuleException('No available IPv4 addresses remain in the' . $scope . $bridgeHint . '.');
        });
    }

    public function releaseForService(int $serverId, int $serviceId): int
    {
        $this->ensureSchema();

        if ($serverId <= 0 || $serviceId <= 0) {
            return 0;
        }

        return (int) Capsule::table(self::TABLE)
            ->where('server_id', '=', $serverId)
            ->where('service_id', '=', $serviceId)
            ->update(array(
                'service_id' => null,
                'updated_at' => $this->now(),
            ));
    }

    public function releaseEntry(int $serverId, int $entryId): void
    {
        $this->ensureSchema();

        $affected = Capsule::table(self::TABLE)
            ->where('server_id', '=', $serverId)
            ->where('id', '=', $entryId)
            ->update(array(
                'service_id' => null,
                'updated_at' => $this->now(),
            ));

        if ($affected === 0) {
            throw new ModuleException('Unable to release the requested IP pool entry.');
        }
    }

    public function deleteEntry(int $serverId, int $entryId): void
    {
        $this->ensureSchema();

        $affected = Capsule::table(self::TABLE)
            ->where('server_id', '=', $serverId)
            ->where('id', '=', $entryId)
            ->whereNull('service_id')
            ->delete();

        if ($affected === 0) {
            throw new ModuleException('Only free IP pool entries can be deleted.');
        }
    }

    private function availableCandidateQueries(int $serverId, string $poolName, string $bridge): array
    {
        $poolName = $this->normalizePoolName($poolName);
        $bridge = $this->normalizeBridge($bridge);
        $queries = array();

        if ($poolName !== '' && $bridge !== '') {
            $queries[] = $this->baseAvailableQuery($serverId)->where('pool_name', '=', $poolName)->where('bridge', '=', $bridge);
            $queries[] = $this->baseAvailableQuery($serverId)->where('pool_name', '=', $poolName)->where('bridge', '=', '');

            return $queries;
        }

        if ($poolName !== '') {
            $queries[] = $this->baseAvailableQuery($serverId)->where('pool_name', '=', $poolName);

            return $queries;
        }

        if ($bridge !== '') {
            $queries[] = $this->baseAvailableQuery($serverId)->where('bridge', '=', $bridge);
            $queries[] = $this->baseAvailableQuery($serverId)->where('bridge', '=', '');

            return $queries;
        }

        $queries[] = $this->baseAvailableQuery($serverId);

        return $queries;
    }

    private function baseAvailableQuery(int $serverId)
    {
        return Capsule::table(self::TABLE)
            ->where('server_id', '=', $serverId)
            ->whereNull('service_id');
    }

    private function normalizeRow($row): array
    {
        return array(
            'id' => isset($row->id) ? (int) $row->id : 0,
            'server_id' => isset($row->server_id) ? (int) $row->server_id : 0,
            'pool_name' => isset($row->pool_name) ? (string) $row->pool_name : '',
            'address' => isset($row->address) ? (string) $row->address : '',
            'prefix' => isset($row->prefix) ? (int) $row->prefix : 0,
            'gateway' => isset($row->gateway) ? (string) $row->gateway : '',
            'bridge' => isset($row->bridge) ? (string) $row->bridge : '',
            'service_id' => isset($row->service_id) && $row->service_id !== null ? (int) $row->service_id : null,
            'service_status' => isset($row->service_status) ? (string) $row->service_status : '',
            'service_domain' => isset($row->service_domain) ? (string) $row->service_domain : '',
            'product_name' => isset($row->product_name) ? (string) $row->product_name : '',
        );
    }

    private function normalizePoolName(string $value): string
    {
        $normalized = trim($value);

        return substr($normalized, 0, 64);
    }

    private function normalizeBridge(string $value): string
    {
        $normalized = trim($value);

        return substr($normalized, 0, 64);
    }

    private function normalizeNullableString(string $value): ?string
    {
        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
