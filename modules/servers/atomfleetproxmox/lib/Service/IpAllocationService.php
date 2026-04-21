<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Service;

use AtomFleet\Whmcs\Proxmox\Exception\ModuleException;
use AtomFleet\Whmcs\Proxmox\Repository\IpPoolRepository;
use AtomFleet\Whmcs\Proxmox\Support\ModuleParameters;

final class IpAllocationService
{
    /** @var ModuleParameters */
    private $params;

    /** @var IpPoolRepository */
    private $repository;

    public function __construct(ModuleParameters $params, ?IpPoolRepository $repository = null)
    {
        $this->params = $params;
        $this->repository = $repository ?: new IpPoolRepository();
    }

    public function ensureAssignedIpv4(): array
    {
        $manual = $this->manualIpv4Config();

        if ($manual['address'] !== '') {
            $manual['source'] = 'manual';
            $manual['newlyAllocated'] = false;
            $this->persistManualProperties($manual);

            return $manual;
        }

        $existing = $this->existingPoolAssignment();

        if ($existing['address'] !== '') {
            return $existing;
        }

        if ($this->assignmentMode() !== 'pool') {
            return array(
                'address' => '',
                'prefix' => '',
                'gateway' => '',
                'source' => 'dhcp',
                'newlyAllocated' => false,
            );
        }

        $serverId = $this->params->serverId();
        $serviceId = $this->params->serviceId();

        if ($serverId <= 0 || $serviceId <= 0) {
            throw new ModuleException('WHMCS did not provide a server or service identifier for automatic IP allocation.');
        }

        $row = $this->repository->allocateForService(
            $serverId,
            $serviceId,
            trim((string) $this->params->config('IPv4 Pool', '')),
            trim((string) $this->params->config('Network Bridge', 'vmbr0'))
        );

        $allocation = array(
            'address' => (string) $row['address'],
            'prefix' => (string) $row['prefix'],
            'gateway' => (string) ($row['gateway'] ?? ''),
            'pool' => (string) ($row['pool_name'] ?? ''),
            'bridge' => (string) ($row['bridge'] ?? ''),
            'source' => 'pool',
            'newlyAllocated' => true,
        );

        $this->persistPoolProperties($allocation);

        return $allocation;
    }

    public function releaseAssignedIpv4(bool $clearProperties = true): void
    {
        if ($this->params->serviceProperty('IP Allocation Source', '') === 'pool') {
            $this->repository->releaseForService($this->params->serverId(), $this->params->serviceId());
        }

        if ($clearProperties) {
            $this->clearProperties();
        }
    }

    private function assignmentMode(): string
    {
        $mode = strtolower(trim((string) $this->params->config('IPv4 Assignment Mode', 'pool')));

        return in_array($mode, array('pool', 'manual'), true) ? $mode : 'pool';
    }

    private function manualIpv4Config(): array
    {
        $address = trim((string) $this->params->customField('IPv4 Address', ''));

        if ($address === '') {
            $address = trim((string) $this->params->get('dedicatedip', ''));
        }

        if ($address === '') {
            $address = trim((string) $this->params->serviceProperty('Dedicated IP', ''));
        }

        if ($address === '' || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return array(
                'address' => '',
                'prefix' => '',
                'gateway' => '',
            );
        }

        $prefix = trim((string) $this->params->customField('IPv4 Prefix', ''));

        if ($prefix === '') {
            $prefix = trim((string) $this->params->customField('IPv4 CIDR', ''));
        }

        $gateway = trim((string) $this->params->customField('IPv4 Gateway', ''));

        return array(
            'address' => $address,
            'prefix' => $prefix,
            'gateway' => $gateway,
        );
    }

    private function existingPoolAssignment(): array
    {
        if ($this->params->serviceProperty('IP Allocation Source', '') !== 'pool') {
            return array(
                'address' => '',
                'prefix' => '',
                'gateway' => '',
                'source' => '',
                'newlyAllocated' => false,
            );
        }

        $address = trim((string) $this->params->serviceProperty('Allocated IPv4', ''));

        if ($address === '') {
            return array(
                'address' => '',
                'prefix' => '',
                'gateway' => '',
                'source' => '',
                'newlyAllocated' => false,
            );
        }

        return array(
            'address' => $address,
            'prefix' => trim((string) $this->params->serviceProperty('Allocated IPv4 Prefix', '')),
            'gateway' => trim((string) $this->params->serviceProperty('Allocated IPv4 Gateway', '')),
            'pool' => trim((string) $this->params->serviceProperty('IP Pool', '')),
            'bridge' => trim((string) $this->params->serviceProperty('IP Bridge', '')),
            'source' => 'pool',
            'newlyAllocated' => false,
        );
    }

    private function persistManualProperties(array $allocation): void
    {
        $this->params->saveServiceProperties(array(
            'Dedicated IP' => $allocation['address'],
            'Allocated IPv4' => '',
            'Allocated IPv4 Prefix' => '',
            'Allocated IPv4 Gateway' => '',
            'IP Allocation Source' => 'manual',
            'IP Pool' => '',
            'IP Bridge' => '',
        ));
    }

    private function persistPoolProperties(array $allocation): void
    {
        $this->params->saveServiceProperties(array(
            'Dedicated IP' => $allocation['address'],
            'Allocated IPv4' => $allocation['address'],
            'Allocated IPv4 Prefix' => $allocation['prefix'],
            'Allocated IPv4 Gateway' => $allocation['gateway'],
            'IP Allocation Source' => 'pool',
            'IP Pool' => $allocation['pool'],
            'IP Bridge' => $allocation['bridge'],
        ));
    }

    private function clearProperties(): void
    {
        $this->params->saveServiceProperties(array(
            'Dedicated IP' => '',
            'Allocated IPv4' => '',
            'Allocated IPv4 Prefix' => '',
            'Allocated IPv4 Gateway' => '',
            'IP Allocation Source' => '',
            'IP Pool' => '',
            'IP Bridge' => '',
        ));
    }
}
