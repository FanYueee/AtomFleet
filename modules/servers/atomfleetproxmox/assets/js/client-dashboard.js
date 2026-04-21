(function () {
    "use strict";

    function ready(callback) {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", callback);
            return;
        }

        callback();
    }

    function formatBytes(bytes) {
        const units = ["B", "KB", "MB", "GB", "TB", "PB"];
        let value = Math.max(Number(bytes) || 0, 0);
        let unitIndex = 0;

        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }

        return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
    }

    function formatRate(bytesPerSecond) {
        return `${formatBytes(bytesPerSecond)}/s`;
    }

    function formatTimestamp(isoString) {
        if (!isoString) {
            return "--";
        }

        const date = new Date(isoString);

        if (Number.isNaN(date.getTime())) {
            return isoString;
        }

        return new Intl.DateTimeFormat(undefined, {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            year: "numeric",
            month: "2-digit",
            day: "2-digit"
        }).format(date);
    }

    class AtomFleetDashboard {
        constructor(root) {
            this.root = root;
            this.apiUrl = root.dataset.apiUrl;
            this.bootstrapScript = document.getElementById(root.dataset.bootstrapId);
            this.state = {
                dashboard: this.readBootstrap(),
                samples: [],
                counters: null
            };
            this.elements = {
                flash: root.querySelector('[data-role="flash"]'),
                buttons: Array.from(root.querySelectorAll("[data-power-action]")),
                charts: {
                    cpu: root.querySelector('[data-chart="cpu"]'),
                    memory: root.querySelector('[data-chart="memory"]'),
                    disk: root.querySelector('[data-chart="disk"]'),
                    network: root.querySelector('[data-chart="network"]'),
                    cpuLarge: root.querySelector('[data-chart="cpu-large"]'),
                    memoryLarge: root.querySelector('[data-chart="memory-large"]'),
                    diskLarge: root.querySelector('[data-chart="disk-large"]')
                }
            };

            if (!this.state.dashboard) {
                return;
            }

            this.bindEvents();
            this.applyDashboard(this.state.dashboard);
            this.timer = window.setInterval(() => this.poll(), 15000);
            window.addEventListener("resize", () => this.renderCharts());
        }

        readBootstrap() {
            if (!this.bootstrapScript) {
                return null;
            }

            try {
                return JSON.parse(this.bootstrapScript.textContent || "{}");
            } catch (error) {
                this.flash("Unable to parse dashboard bootstrap payload.", true);
                return null;
            }
        }

        bindEvents() {
            this.elements.buttons.forEach((button) => {
                button.addEventListener("click", async () => {
                    if (button.disabled) {
                        return;
                    }

                    const action = button.dataset.powerAction;
                    const confirmed = action === "stop"
                        ? window.confirm("Force Stop will cut power immediately. Continue?")
                        : true;

                    if (!confirmed) {
                        return;
                    }

                    await this.runPowerAction(action);
                });
            });
        }

        async poll() {
            try {
                const payload = await this.fetchJson("dashboard");
                this.applyDashboard(payload);
            } catch (error) {
                this.flash(error.message, true);
            }
        }

        async runPowerAction(action) {
            this.setBusyState(true);

            try {
                const payload = await this.fetchJson("power", {
                    method: "POST",
                    body: { powerAction: action }
                });

                this.flash(payload.message || `Power action "${action}" sent.`);
                this.applyDashboard(payload.dashboard || this.state.dashboard);
            } catch (error) {
                this.flash(error.message, true);
            } finally {
                this.setBusyState(false);
            }
        }

        async fetchJson(endpoint, options = {}) {
            const url = `${this.apiUrl}&endpoint=${encodeURIComponent(endpoint)}`;
            const requestOptions = {
                credentials: "same-origin",
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                },
                method: options.method || "GET"
            };

            if (options.body) {
                const params = new URLSearchParams();
                Object.entries(options.body).forEach(([key, value]) => {
                    params.append(key, value);
                });

                requestOptions.body = params.toString();
                requestOptions.headers["Content-Type"] = "application/x-www-form-urlencoded; charset=UTF-8";
            }

            const response = await window.fetch(url, requestOptions);
            const rawPayload = await response.text();
            let payload = null;

            try {
                payload = rawPayload ? JSON.parse(rawPayload) : {};
            } catch (error) {
                throw new Error(rawPayload || `Unexpected API response (${response.status}).`);
            }

            if (!response.ok || payload.error) {
                throw new Error(payload.error || "Unexpected API response.");
            }

            return payload;
        }

        applyDashboard(dashboard) {
            if (!dashboard) {
                return;
            }

            this.state.dashboard = dashboard;
            const stats = dashboard.stats || {};
            const spec = dashboard.spec || {};
            const sample = this.buildSample(stats);

            if (sample) {
                this.state.samples.push(sample);
                this.state.samples = this.state.samples.slice(-48);
            }

            this.writeField("instance-name", dashboard.instance && dashboard.instance.name);
            this.writeField("vmid", dashboard.instance && dashboard.instance.vmid ? dashboard.instance.vmid : "--");
            this.writeField("node", dashboard.instance && dashboard.instance.node ? dashboard.instance.node : "auto");
            this.writeField("status-pill", stats.statusLabel || "Unknown");
            this.writeField("last-updated", formatTimestamp(dashboard.meta && dashboard.meta.updatedAt));
            this.writeField("uptime", stats.uptimeLabel || "--");
            this.writeWarning(dashboard.meta && dashboard.meta.warning || "");

            this.writeField("cpu-value", stats.cpu && stats.cpu.display);
            this.writeField("memory-value", stats.memory && stats.memory.display);
            this.writeField("memory-subtitle", stats.memory && stats.memory.subtitle);
            this.writeField("disk-value", stats.disk && stats.disk.display);
            this.writeField("disk-subtitle", stats.disk && stats.disk.subtitle);

            this.writeField("spec-cpu", `${spec.cpuCores || 0}`);
            this.writeField("spec-memory", `${spec.memoryMb || 0} MB`);
            this.writeField("spec-disk", `${spec.diskGb || 0} GB`);
            this.writeField("spec-bridge", spec.bridge || "--");
            this.writeField("spec-ipv4", spec.primaryIpv4 || "--");
            this.writeField("spec-system", spec.systemLabel || "--");

            if (sample) {
                this.writeField(
                    "network-summary",
                    `RX ${formatRate(sample.rxRate)} / TX ${formatRate(sample.txRate)}`
                );
            }

            this.syncButtons(dashboard.actions || []);
            this.renderCharts();
        }

        buildSample(stats) {
            const now = new Date();
            const counters = stats.network || {};
            const previous = this.state.counters;
            const current = {
                at: now,
                netIn: Number(counters.inBytes || 0),
                netOut: Number(counters.outBytes || 0),
                read: Number(counters.readBytes || 0),
                write: Number(counters.writeBytes || 0)
            };

            let rxRate = 0;
            let txRate = 0;

            if (previous) {
                const deltaSeconds = Math.max((now - previous.at) / 1000, 1);
                rxRate = Math.max((current.netIn - previous.netIn) / deltaSeconds, 0);
                txRate = Math.max((current.netOut - previous.netOut) / deltaSeconds, 0);
            }

            this.state.counters = current;

            return {
                at: now,
                cpu: Number(stats.cpu && stats.cpu.percent || 0),
                memory: Number(stats.memory && stats.memory.percent || 0),
                disk: Number(stats.disk && stats.disk.percent || 0),
                rxRate,
                txRate
            };
        }

        syncButtons(actions) {
            const byKey = new Map(actions.map((action) => [action.key, action]));

            this.elements.buttons.forEach((button) => {
                const action = byKey.get(button.dataset.powerAction);
                button.disabled = !(action && action.enabled);
            });
        }

        renderCharts() {
            if (!window.d3) {
                this.flash("d3.js failed to load. Charts are unavailable.", true);
                return;
            }

            const samples = this.state.samples;

            this.renderPercentChart(this.elements.charts.cpu, samples, "cpu", "#51d0c4");
            this.renderPercentChart(this.elements.charts.memory, samples, "memory", "#64b5ff");
            this.renderPercentChart(this.elements.charts.disk, samples, "disk", "#ffb84d");

            this.renderPercentChart(this.elements.charts.cpuLarge, samples, "cpu", "#51d0c4", true);
            this.renderPercentChart(this.elements.charts.memoryLarge, samples, "memory", "#64b5ff", true);
            this.renderPercentChart(this.elements.charts.diskLarge, samples, "disk", "#ffb84d", true);

            this.renderNetworkChart(this.elements.charts.network, samples);
        }

        renderPercentChart(container, samples, key, color, withAxes = false) {
            if (!container) {
                return;
            }

            const width = Math.max(container.clientWidth, 240);
            const height = Math.max(container.clientHeight, withAxes ? 220 : 96);
            const margin = withAxes
                ? { top: 10, right: 12, bottom: 28, left: 38 }
                : { top: 6, right: 6, bottom: 6, left: 6 };
            const innerWidth = width - margin.left - margin.right;
            const innerHeight = height - margin.top - margin.bottom;
            const d3 = window.d3;

            d3.select(container).selectAll("*").remove();
            const svg = d3.select(container)
                .append("svg")
                .attr("class", "afp-svg")
                .attr("viewBox", `0 0 ${width} ${height}`)
                .attr("preserveAspectRatio", "none");

            if (!samples.length) {
                svg.append("text")
                    .attr("x", width / 2)
                    .attr("y", height / 2)
                    .attr("fill", "rgba(143, 181, 191, 0.72)")
                    .attr("font-size", 12)
                    .attr("text-anchor", "middle")
                    .text("Waiting for data");
                return;
            }

            const x = d3.scaleTime()
                .domain(d3.extent(samples, (sample) => sample.at))
                .range([margin.left, margin.left + innerWidth]);
            const y = d3.scaleLinear()
                .domain([0, 100])
                .range([margin.top + innerHeight, margin.top]);
            const line = d3.line()
                .x((sample) => x(sample.at))
                .y((sample) => y(sample[key]))
                .curve(d3.curveMonotoneX);
            const area = d3.area()
                .x((sample) => x(sample.at))
                .y0(margin.top + innerHeight)
                .y1((sample) => y(sample[key]))
                .curve(d3.curveMonotoneX);

            if (withAxes) {
                svg.append("g")
                    .attr("class", "afp-grid")
                    .attr("transform", `translate(${margin.left},0)`)
                    .call(d3.axisLeft(y).ticks(4).tickSize(-innerWidth).tickFormat((value) => `${value}%`));

                svg.append("g")
                    .attr("class", "afp-axis")
                    .attr("transform", `translate(0,${margin.top + innerHeight})`)
                    .call(d3.axisBottom(x).ticks(4).tickFormat(d3.timeFormat("%H:%M:%S")));

                svg.append("g")
                    .attr("class", "afp-axis")
                    .attr("transform", `translate(${margin.left},0)`)
                    .call(d3.axisLeft(y).ticks(4).tickFormat((value) => `${value}%`));
            }

            svg.append("path")
                .datum(samples)
                .attr("fill", `${color}22`)
                .attr("d", area);

            svg.append("path")
                .datum(samples)
                .attr("fill", "none")
                .attr("stroke", color)
                .attr("stroke-width", withAxes ? 2.4 : 2)
                .attr("d", line);
        }

        renderNetworkChart(container, samples) {
            if (!container) {
                return;
            }

            const d3 = window.d3;
            const width = Math.max(container.clientWidth, 240);
            const height = Math.max(container.clientHeight, 220);
            const margin = { top: 10, right: 14, bottom: 28, left: 48 };
            const innerWidth = width - margin.left - margin.right;
            const innerHeight = height - margin.top - margin.bottom;

            d3.select(container).selectAll("*").remove();
            const svg = d3.select(container)
                .append("svg")
                .attr("class", "afp-svg")
                .attr("viewBox", `0 0 ${width} ${height}`)
                .attr("preserveAspectRatio", "none");

            if (!samples.length) {
                svg.append("text")
                    .attr("x", width / 2)
                    .attr("y", height / 2)
                    .attr("fill", "rgba(143, 181, 191, 0.72)")
                    .attr("font-size", 12)
                    .attr("text-anchor", "middle")
                    .text("Waiting for network samples");
                return;
            }

            const maxRate = Math.max(
                1,
                ...samples.map((sample) => Math.max(sample.rxRate, sample.txRate))
            );
            const x = d3.scaleTime()
                .domain(d3.extent(samples, (sample) => sample.at))
                .range([margin.left, margin.left + innerWidth]);
            const y = d3.scaleLinear()
                .domain([0, maxRate * 1.15])
                .range([margin.top + innerHeight, margin.top]);
            const makeLine = (key) => d3.line()
                .x((sample) => x(sample.at))
                .y((sample) => y(sample[key]))
                .curve(d3.curveMonotoneX);

            svg.append("g")
                .attr("class", "afp-grid")
                .attr("transform", `translate(${margin.left},0)`)
                .call(d3.axisLeft(y).ticks(4).tickSize(-innerWidth).tickFormat((value) => formatRate(value)));

            svg.append("g")
                .attr("class", "afp-axis")
                .attr("transform", `translate(0,${margin.top + innerHeight})`)
                .call(d3.axisBottom(x).ticks(4).tickFormat(d3.timeFormat("%H:%M:%S")));

            svg.append("g")
                .attr("class", "afp-axis")
                .attr("transform", `translate(${margin.left},0)`)
                .call(d3.axisLeft(y).ticks(4).tickFormat((value) => formatRate(value)));

            svg.append("path")
                .datum(samples)
                .attr("fill", "none")
                .attr("stroke", "#51d0c4")
                .attr("stroke-width", 2.4)
                .attr("d", makeLine("rxRate"));

            svg.append("path")
                .datum(samples)
                .attr("fill", "none")
                .attr("stroke", "#ffb84d")
                .attr("stroke-width", 2.4)
                .attr("d", makeLine("txRate"));
        }

        writeField(name, value) {
            const element = this.root.querySelector(`[data-field="${name}"]`);

            if (!element) {
                return;
            }

            element.textContent = value == null || value === "" ? "--" : value;
        }

        writeWarning(message) {
            const element = this.root.querySelector('[data-field="warning"]');

            if (!element) {
                return;
            }

            if (!message) {
                element.style.display = "none";
                element.textContent = "";
                return;
            }

            element.style.display = "";
            element.textContent = message;
        }

        setBusyState(isBusy) {
            if (isBusy) {
                this.elements.buttons.forEach((button) => {
                    button.disabled = true;
                });
                return;
            }

            this.syncButtons(this.state.dashboard && this.state.dashboard.actions || []);
        }

        flash(message, isError = false) {
            if (!this.elements.flash) {
                return;
            }

            this.elements.flash.textContent = message || "";
            this.elements.flash.classList.toggle("is-error", Boolean(isError));

            if (message) {
                window.clearTimeout(this.flashTimer);
                this.flashTimer = window.setTimeout(() => {
                    this.elements.flash.textContent = "";
                    this.elements.flash.classList.remove("is-error");
                }, 6000);
            }
        }
    }

    ready(() => {
        document.querySelectorAll(".afp-shell").forEach((root) => {
            if (!root.dataset.afpMounted) {
                root.dataset.afpMounted = "1";
                new AtomFleetDashboard(root);
            }
        });
    });
})();
