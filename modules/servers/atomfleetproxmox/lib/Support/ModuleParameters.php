<?php
declare(strict_types=1);

namespace AtomFleet\Whmcs\Proxmox\Support;

final class ModuleParameters
{
    /** @var array */
    private $params;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    public function config(string $name, $default = null)
    {
        $moduleConfig = $this->moduleConfigOptions();

        if (array_key_exists($name, $moduleConfig)) {
            return $moduleConfig[$name];
        }

        $configurableOptions = isset($this->params['configoptions']) && is_array($this->params['configoptions'])
            ? $this->params['configoptions']
            : array();

        return array_key_exists($name, $configurableOptions) ? $configurableOptions[$name] : $default;
    }

    public function intConfig(string $name, int $default = 0): int
    {
        $value = $this->config($name, $default);

        if ($value === '' || $value === null) {
            return $default;
        }

        return (int) $value;
    }

    public function boolConfig(string $name, bool $default = false): bool
    {
        return self::toBool($this->config($name, $default ? 'on' : ''));
    }

    public function customField(string $name, $default = '')
    {
        $customFields = isset($this->params['customfields']) && is_array($this->params['customfields'])
            ? $this->params['customfields']
            : array();

        return array_key_exists($name, $customFields) ? $customFields[$name] : $default;
    }

    public function serviceProperty(string $name, $default = null)
    {
        $serviceProperties = $this->servicePropertiesObject();

        if (!$serviceProperties) {
            return $default;
        }

        try {
            $value = $serviceProperties->get($name);

            return $value === null || $value === '' ? $default : $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public function saveServiceProperties(array $properties): void
    {
        $serviceProperties = $this->servicePropertiesObject();

        if (!$serviceProperties) {
            return;
        }

        $normalized = array();

        foreach ($properties as $name => $value) {
            $normalized[$name] = (string) $value;
        }

        $serviceProperties->save($normalized);
    }

    public function serviceId(): int
    {
        return (int) $this->get('serviceid', 0);
    }

    public function serverId(): int
    {
        $serverId = (int) $this->get('serverid', 0);

        if ($serverId > 0) {
            return $serverId;
        }

        return (int) $this->get('server', 0);
    }

    public function serverHost(): string
    {
        $host = trim((string) $this->get('serverhostname', ''));

        if ($host !== '') {
            return $host;
        }

        return trim((string) $this->get('serverip', ''));
    }

    public function serverPort(): int
    {
        $port = (int) $this->get('serverport', 0);

        return $port > 0 ? $port : 8006;
    }

    public function scheme(): string
    {
        return self::toBool($this->get('serversecure', true)) ? 'https' : 'http';
    }

    public function apiBaseUri(): string
    {
        return sprintf('%s://%s:%d/api2/json', $this->scheme(), $this->serverHost(), $this->serverPort());
    }

    public function panelBaseUrl(): string
    {
        return sprintf('%s://%s:%d', $this->scheme(), $this->serverHost(), $this->serverPort());
    }

    public function serverUsername(): string
    {
        return trim((string) $this->get('serverusername', ''));
    }

    public function serverPassword(): string
    {
        return (string) $this->get('serverpassword', '');
    }

    public function guestType(): string
    {
        return strtolower(trim((string) $this->config('Guest Type', 'qemu')));
    }

    public function instanceName(): string
    {
        $preferred = trim((string) $this->serviceProperty('Instance Name', ''));

        if ($preferred === '') {
            $preferred = trim((string) $this->get('domain', ''));
        }

        if ($preferred === '') {
            $preferred = trim((string) $this->get('username', ''));
        }

        if ($preferred === '') {
            $preferred = 'af-svc-' . $this->serviceId();
        }

        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9.-]+/', '-', $preferred));
        $normalized = trim($normalized, '-.');

        if ($normalized === '') {
            $normalized = 'af-svc-' . $this->serviceId();
        }

        return substr($normalized, 0, 63);
    }

    private function servicePropertiesObject()
    {
        if (empty($this->params['model']) || !is_object($this->params['model'])) {
            return null;
        }

        if (!property_exists($this->params['model'], 'serviceProperties')) {
            return null;
        }

        return $this->params['model']->serviceProperties;
    }

    private function moduleConfigOptions(): array
    {
        static $configOptionNames;

        if ($configOptionNames === null) {
            $configOptionNames = array();

            if (function_exists('atomfleetproxmox_ConfigOptions')) {
                $configOptionNames = array_keys((array) atomfleetproxmox_ConfigOptions());
            }
        }

        $values = array();

        foreach ($configOptionNames as $index => $optionName) {
            $paramKey = 'configoption' . ($index + 1);

            if (!array_key_exists($paramKey, $this->params)) {
                continue;
            }

            $values[$optionName] = $this->params[$paramKey];
        }

        return $values;
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, array('1', 'on', 'yes', 'true'), true);
    }
}
