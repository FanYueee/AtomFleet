<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Service;

use WHMCS\Service\Status;

final class ClusterService extends AbstractProxmoxService
{
    public function getNodeOptions(): array
    {
        $options = array('auto' => 'Auto-select first online node');

        foreach ($this->getNodes() as $node) {
            $options[$node['name']] = $node['label'];
        }

        return $options;
    }

    public function getStorageOptions(string $preferredNode = ''): array
    {
        $options = array();
        $node = $this->resolvePreferredNode($preferredNode);
        $storages = $this->client->get('/nodes/' . rawurlencode($node) . '/storage');

        if (!is_array($storages)) {
            return $options;
        }

        foreach ($storages as $storage) {
            if (empty($storage['storage'])) {
                continue;
            }

            $storageName = (string) $storage['storage'];
            $enabled = !isset($storage['enabled']) || (int) $storage['enabled'] === 1;

            if (!$enabled) {
                continue;
            }

            $options[$storageName] = $storageName . ' (' . $node . ')';
        }

        asort($options);

        return $options;
    }

    public function getBridgeOptions(string $preferredNode = ''): array
    {
        $options = array();
        $node = $this->resolvePreferredNode($preferredNode);
        $bridges = $this->client->get('/nodes/' . rawurlencode($node) . '/network');

        if (!is_array($bridges)) {
            return $options;
        }

        foreach ($bridges as $bridge) {
            if (($bridge['type'] ?? '') !== 'bridge' || empty($bridge['iface'])) {
                continue;
            }

            $iface = (string) $bridge['iface'];
            $options[$iface] = $iface . ' (' . $node . ')';
        }

        asort($options);

        return $options;
    }

    public function getQemuTemplateOptions(string $preferredNode = ''): array
    {
        $options = array();
        $nodes = $preferredNode !== '' && strtolower($preferredNode) !== 'auto'
            ? array($this->resolvePreferredNode($preferredNode))
            : array_column($this->getNodes(), 'name');

        foreach ($nodes as $node) {
            $guests = $this->client->get('/nodes/' . rawurlencode($node) . '/qemu');

            if (!is_array($guests)) {
                continue;
            }

            foreach ($guests as $guest) {
                if (empty($guest['template']) || empty($guest['vmid'])) {
                    continue;
                }

                $vmid = (int) $guest['vmid'];
                $name = !empty($guest['name']) ? $guest['name'] : ('template-' . $vmid);
                $options[(string) $vmid] = $vmid . ' - ' . $name . ' (' . $node . ')';
            }
        }

        asort($options);

        return $options;
    }

    public function getLxcTemplateOptions(string $preferredNode = ''): array
    {
        $options = array();
        $node = $this->resolvePreferredNode($preferredNode);
        $storages = $this->client->get('/nodes/' . rawurlencode($node) . '/storage');

        if (!is_array($storages)) {
            return $options;
        }

        foreach ($storages as $storage) {
            if (empty($storage['storage'])) {
                continue;
            }

            $storageName = (string) $storage['storage'];
            $content = $this->client->get(
                '/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode($storageName) . '/content',
                array('content' => 'vztmpl')
            );

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $item) {
                if (empty($item['volid'])) {
                    continue;
                }

                $volid = (string) $item['volid'];
                $options[$volid] = $volid . ' (' . $node . ')';
            }
        }

        asort($options);

        return $options;
    }

    public function listWhmcsSyncAccounts(): array
    {
        $accounts = array();

        foreach ($this->getGuestResources() as $guest) {
            $vmid = (int) $guest['vmid'];
            $uptime = (int) ($guest['uptime'] ?? 0);
            $createdAt = date('Y-m-d H:i:s', max(time() - $uptime, 0));
            $name = !empty($guest['name']) ? (string) $guest['name'] : ('vm-' . $vmid);
            $guestType = $this->normalizeGuestType((string) ($guest['type'] ?? ''));
            $status = strtolower((string) ($guest['status'] ?? ''));

            $accounts[] = array(
                'email' => 'guest-' . $vmid . '@local.invalid',
                'userName' => (string) $vmid,
                'domain' => $name,
                'uniqueIdentifier' => (string) $vmid,
                'product' => $guestType,
                'primaryip' => '',
                'created' => $createdAt,
                'status' => $status === 'running' ? Status::ACTIVE : Status::SUSPENDED,
            );
        }

        return $accounts;
    }

    public function getAdminOverview(): array
    {
        $version = $this->client->get('/version');
        $nodes = $this->getNodes();
        $guests = $this->getGuestResources();
        $running = 0;
        $stopped = 0;

        foreach ($guests as $guest) {
            if (($guest['status'] ?? '') === 'running') {
                $running++;
            } else {
                $stopped++;
            }
        }

        return array(
            'panelUrl' => $this->panelUrl(),
            'version' => !empty($version['version']) ? (string) $version['version'] : 'unknown',
            'release' => !empty($version['release']) ? (string) $version['release'] : '',
            'repoid' => !empty($version['repoid']) ? (string) $version['repoid'] : '',
            'summary' => array(
                'nodes' => count($nodes),
                'guests' => count($guests),
                'running' => $running,
                'stopped' => $stopped,
            ),
            'nodes' => $nodes,
            'guests' => $guests,
        );
    }

    public function getGuestResources(): array
    {
        $resources = $this->getClusterVmResources();
        usort($resources, function ($left, $right) {
            return ((int) ($left['vmid'] ?? 0)) <=> ((int) ($right['vmid'] ?? 0));
        });

        return array_map(function ($guest) {
            $cpuPercent = $this->percent($guest['cpu'] ?? 0);
            $memoryUsed = (int) ($guest['mem'] ?? 0);
            $memoryTotal = (int) ($guest['maxmem'] ?? 0);
            $memoryPercent = $memoryTotal > 0 ? round(($memoryUsed / $memoryTotal) * 100, 1) : 0.0;

            return array(
                'vmid' => (int) ($guest['vmid'] ?? 0),
                'name' => !empty($guest['name']) ? (string) $guest['name'] : '',
                'node' => !empty($guest['node']) ? (string) $guest['node'] : '',
                'guestType' => $this->normalizeGuestType((string) ($guest['type'] ?? '')),
                'guestTypeLabel' => strtoupper($this->normalizeGuestType((string) ($guest['type'] ?? ''))),
                'status' => strtolower((string) ($guest['status'] ?? 'unknown')),
                'statusLabel' => $this->statusLabel((string) ($guest['status'] ?? 'unknown')),
                'cpuPercent' => $cpuPercent,
                'memoryPercent' => $memoryPercent,
                'memoryDisplay' => $this->formatBytes($memoryUsed) . ' / ' . $this->formatBytes($memoryTotal),
                'uptime' => (int) ($guest['uptime'] ?? 0),
                'uptimeLabel' => $this->formatDuration((int) ($guest['uptime'] ?? 0)),
                'type' => (string) ($guest['type'] ?? ''),
            );
        }, $resources);
    }

    public function getNodes(): array
    {
        $nodes = $this->client->get('/nodes');

        if (!is_array($nodes)) {
            return array();
        }

        usort($nodes, function ($left, $right) {
            return strcmp((string) ($left['node'] ?? ''), (string) ($right['node'] ?? ''));
        });

        return array_map(function ($node) {
            $name = !empty($node['node']) ? (string) $node['node'] : '';
            $status = strtolower((string) ($node['status'] ?? 'unknown'));

            return array(
                'name' => $name,
                'status' => $status,
                'statusLabel' => $this->statusLabel($status),
                'cpuPercent' => $this->percent($node['cpu'] ?? 0),
                'memoryPercent' => !empty($node['maxmem'])
                    ? round((((int) ($node['mem'] ?? 0)) / ((int) $node['maxmem'])) * 100, 1)
                    : 0.0,
                'label' => $name . ' (' . $this->statusLabel($status) . ')',
            );
        }, $nodes);
    }
}
