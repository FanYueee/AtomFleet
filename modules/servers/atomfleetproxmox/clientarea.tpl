<link rel="stylesheet" href="{$moduleCssUrl|escape:'html'}" />

<div
    class="afp-shell{if !$dashboard.meta.provisioned} is-unprovisioned{/if}"
    id="atomfleet-proxmox-{$serviceId}"
    data-api-url="{$apiUrl|escape:'html'}"
    data-bootstrap-id="{$bootstrapId|escape:'html'}"
>
    {if $dashboard.meta.warning}
        <div class="afp-warning" data-field="warning">{$dashboard.meta.warning|escape:'html'}</div>
    {else}
        <div class="afp-warning" data-field="warning" style="display:none;"></div>
    {/if}

    <section class="afp-hero">
        <div class="afp-hero-copy">
            <p class="afp-kicker">AtomFleet Proxmox Control Surface</p>
            <h2 class="afp-title" data-field="instance-name">{$dashboard.instance.name|escape:'html'}</h2>
            <div class="afp-meta">
                <span class="afp-status-pill" data-field="status-pill">{$dashboard.stats.statusLabel|escape:'html'}</span>
                <span>VMID <strong data-field="vmid">{if $dashboard.instance.vmid}{$dashboard.instance.vmid}{else}--{/if}</strong></span>
                <span>Node <strong data-field="node">{$dashboard.instance.node|default:'auto'|escape:'html'}</strong></span>
            </div>
            <p class="afp-summary">
                {$groupname|escape:'html'} / {$product|escape:'html'}
                {if $domain}
                    <span class="afp-divider"></span>
                    {$domain|escape:'html'}
                {/if}
            </p>
        </div>

        <aside class="afp-hero-panel">
            <div class="afp-panel-head">
                <span class="afp-panel-label">Live Control</span>
            </div>
            <div class="afp-actions">
                {foreach from=$dashboard.actions item=action}
                    <button
                        class="afp-action-button"
                        type="button"
                        data-power-action="{$action.key|escape:'html'}"
                        {if !$action.enabled}disabled="disabled"{/if}
                    >
                        {$action.label|escape:'html'}
                    </button>
                {/foreach}
            </div>
            <div class="afp-panel-foot">
                <span class="afp-foot-label">Last refresh</span>
                <strong data-field="last-updated">{$dashboard.meta.updatedAt|escape:'html'}</strong>
            </div>
            <div class="afp-flash" data-role="flash"></div>
        </aside>
    </section>

    <section class="afp-metric-grid">
        <article class="afp-metric-card">
            <div class="afp-card-head">
                <span>CPU</span>
                <strong data-field="cpu-value">{$dashboard.stats.cpu.display|escape:'html'}</strong>
            </div>
            <div class="afp-metric-subtitle" data-field="cpu-subtitle">Instant host usage</div>
            <div class="afp-sparkline" data-chart="cpu"></div>
        </article>

        <article class="afp-metric-card">
            <div class="afp-card-head">
                <span>Memory</span>
                <strong data-field="memory-value">{$dashboard.stats.memory.display|escape:'html'}</strong>
            </div>
            <div class="afp-metric-subtitle" data-field="memory-subtitle">
                {$dashboard.stats.memory.subtitle|default:''|escape:'html'}
            </div>
            <div class="afp-sparkline" data-chart="memory"></div>
        </article>

        <article class="afp-metric-card">
            <div class="afp-card-head">
                <span>Disk</span>
                <strong data-field="disk-value">{$dashboard.stats.disk.display|escape:'html'}</strong>
            </div>
            <div class="afp-metric-subtitle" data-field="disk-subtitle">
                {$dashboard.stats.disk.subtitle|default:''|escape:'html'}
            </div>
            <div class="afp-sparkline" data-chart="disk"></div>
        </article>
    </section>

    <section class="afp-content-grid">
        <article class="afp-panel-card">
            <div class="afp-panel-head">
                <span class="afp-panel-label">Topology</span>
                <span class="afp-panel-quiet" data-field="uptime">{$dashboard.stats.uptimeLabel|escape:'html'}</span>
            </div>
            <dl class="afp-spec-grid">
                <div>
                    <dt>CPU Cores</dt>
                    <dd data-field="spec-cpu">{$dashboard.spec.cpuCores}</dd>
                </div>
                <div>
                    <dt>Memory</dt>
                    <dd data-field="spec-memory">{$dashboard.spec.memoryMb} MB</dd>
                </div>
                <div>
                    <dt>Disk Target</dt>
                    <dd data-field="spec-disk">{$dashboard.spec.diskGb} GB</dd>
                </div>
                <div>
                    <dt>Bridge</dt>
                    <dd data-field="spec-bridge">{$dashboard.spec.bridge|escape:'html'}</dd>
                </div>
                <div>
                    <dt>Primary IPv4</dt>
                    <dd data-field="spec-ipv4">{$dashboard.spec.primaryIpv4|default:'--'|escape:'html'}</dd>
                </div>
                <div>
                    <dt>System</dt>
                    <dd data-field="spec-system">{$dashboard.spec.systemLabel|default:'--'|escape:'html'}</dd>
                </div>
            </dl>
        </article>

        <article class="afp-panel-card afp-chart-card">
            <div class="afp-panel-head">
                <span class="afp-panel-label">Network Throughput</span>
                <span class="afp-panel-quiet" data-field="network-summary">Waiting for samples</span>
            </div>
            <div class="afp-network-chart" data-chart="network"></div>
        </article>
    </section>

    <section class="afp-chart-stack">
        <article class="afp-panel-card afp-chart-card">
            <div class="afp-panel-head">
                <span class="afp-panel-label">CPU Timeline</span>
                <span class="afp-panel-quiet">Rolling session history</span>
            </div>
            <div class="afp-chart" data-chart="cpu-large"></div>
        </article>

        <article class="afp-panel-card afp-chart-card">
            <div class="afp-panel-head">
                <span class="afp-panel-label">Memory Timeline</span>
                <span class="afp-panel-quiet">Live from Proxmox status current</span>
            </div>
            <div class="afp-chart" data-chart="memory-large"></div>
        </article>

        <article class="afp-panel-card afp-chart-card">
            <div class="afp-panel-head">
                <span class="afp-panel-label">Disk Timeline</span>
                <span class="afp-panel-quiet">Session-based spark history</span>
            </div>
            <div class="afp-chart" data-chart="disk-large"></div>
        </article>
    </section>
</div>

<script type="application/json" id="{$bootstrapId|escape:'html'}">{$dashboardJson nofilter}</script>
<script src="https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js"></script>
<script src="{$moduleJsUrl|escape:'html'}"></script>
