(() => {
    'use strict';

    const dataNode = document.getElementById('symbol-graph-data');
    const viewport = document.getElementById('graph-viewport');
    if (!dataNode || !viewport || typeof window.cytoscape !== 'function') return;

    let graph;
    try { graph = JSON.parse(dataNode.textContent || '{}'); } catch { return; }
    const rawNodes = Array.isArray(graph.nodes) ? graph.nodes : [];
    const rawEdges = Array.isArray(graph.edges) ? graph.edges : [];
    const projectId = viewport.closest('[data-project]')?.dataset.project || '';
    const controls = {
        level: document.getElementById('graph-level'), search: document.getElementById('graph-search'),
        type: document.getElementById('graph-type'), relationship: document.getElementById('graph-relationship'),
        subsystem: document.getElementById('graph-subsystem'), feature: document.getElementById('graph-feature'),
        framework: document.getElementById('graph-framework'), risk: document.getElementById('graph-risk'),
        confidence: document.getElementById('graph-confidence'), reset: document.getElementById('graph-reset'),
        fit: document.getElementById('graph-fit'), detail: document.getElementById('graph-detail'),
        direction: document.getElementById('graph-direction'), depth: document.getElementById('graph-depth'),
        resultCount: document.getElementById('graph-result-count'),
        from: document.getElementById('graph-path-from'), to: document.getElementById('graph-path-to'),
        trace: document.getElementById('graph-trace'), pathResult: document.getElementById('graph-path-result'),
    };
    const confidenceRank = { low: 1, medium: 2, high: 3 };
    const riskRank = { low: 1, medium: 2, high: 3 };
    const stronger = (left, right, rank) => (rank[right] || 0) > (rank[left] || 0) ? right : left;
    let selectedId = null;
    let drillFilter = null;
    let lastTap = { id: '', at: 0 };

    const cy = window.cytoscape({
        container: viewport, elements: [], minZoom: 0.18, maxZoom: 3, boxSelectionEnabled: true,
        style: [
            { selector: 'node', style: { 'background-color': '#17252a', 'border-color': '#55746e', 'border-width': 1.5, color: '#e8f0ed', label: 'data(label)', 'font-family': 'ui-monospace, SFMono-Regular, Menlo, monospace', 'font-size': 9, 'text-wrap': 'ellipsis', 'text-max-width': 120, 'text-valign': 'center', 'text-halign': 'center', width: 'mapData(size, 1, 30, 46, 92)', height: 'mapData(size, 1, 30, 34, 64)', shape: 'round-rectangle', 'overlay-opacity': 0 } },
            { selector: 'node[level = "architecture"]', style: { 'background-color': '#15382c', 'border-color': '#77d7a9', 'font-size': 12, width: 'mapData(size, 1, 30, 120, 210)', height: 70 } },
            { selector: 'node[level = "subsystem"]', style: { 'background-color': '#182f35', 'border-color': '#65b9c9', width: 'mapData(size, 1, 30, 100, 180)', height: 58 } },
            { selector: 'node[level = "feature"]', style: { width: 'mapData(size, 1, 30, 100, 180)', height: 58 } },
            { selector: 'node[semantic = "request"]', style: { 'background-color': '#142d3d', 'border-color': '#65bde8' } },
            { selector: 'node[semantic = "service"]', style: { 'background-color': '#2d2440', 'border-color': '#ad8de0' } },
            { selector: 'node[semantic = "data"]', style: { 'background-color': '#352b19', 'border-color': '#e2b561' } },
            { selector: 'node[semantic = "external"]', style: { 'background-color': '#12343a', 'border-color': '#63cfda' } },
            { selector: 'node[semantic = "security"]', style: { 'background-color': '#372126', 'border-color': '#ef8585' } },
            { selector: 'node[semantic = "interface"]', style: { 'background-color': '#18313a', 'border-color': '#76c7d7' } },
            { selector: 'node[risk = "high"]', style: { 'border-color': '#e2747b', 'border-width': 2.5 } },
            { selector: 'node:selected', style: { 'background-color': '#1f513e', 'border-color': '#a2f5c8', 'border-width': 3 } },
            { selector: 'edge', style: { width: 'mapData(count, 1, 12, 1, 5)', 'line-color': '#45645f', 'target-arrow-color': '#45645f', 'target-arrow-shape': 'triangle', 'curve-style': 'bezier', opacity: 0.72, 'arrow-scale': 0.7 } },
            { selector: 'edge[category = "request"]', style: { 'line-color': '#5c9fbe', 'target-arrow-color': '#5c9fbe' } },
            { selector: 'edge[category = "data"]', style: { 'line-color': '#a98a4e', 'target-arrow-color': '#a98a4e' } },
            { selector: 'edge[category = "external"]', style: { 'line-color': '#4e9fa8', 'target-arrow-color': '#4e9fa8' } },
            { selector: 'edge[confidence = "high"]', style: { 'line-color': '#70c99f', 'target-arrow-color': '#70c99f' } },
            { selector: '.muted', style: { opacity: 0.08 } },
            { selector: '.neighbor', style: { opacity: 1, 'border-width': 2.5 } },
            { selector: '.path-highlight', style: { opacity: 1, 'line-color': '#f0c75e', 'target-arrow-color': '#f0c75e', 'border-color': '#f0c75e', 'border-width': 3, 'z-index': 99 } },
            { selector: '.filtered', style: { display: 'none' } },
        ],
    });

    const valueForLevel = (node, level) => {
        if (level === 'architecture') return node.architecture || 'Application';
        if (level === 'subsystem') return node.subsystem || 'Application core';
        if (level === 'feature') return node.feature || 'Core behavior';
        if (level === 'file') return node.path;
        return String(node.id);
    };
    const idFor = (level, value) => level === 'symbol' ? `n${value}` : `${level}:${value}`;
    const semanticFor = (node) => {
        const type = String(node.symbol_type || '').toLowerCase();
        const signal = `${type} ${String(node.name || '')} ${String(node.path || '')}`.toLowerCase();
        if (/auth|security|permission|policy|guard|session/.test(signal)) return 'security';
        if (['route', 'route_handler', 'controller', 'middleware', 'endpoint', 'resolver'].includes(type)) return 'request';
        if (['table', 'model', 'schema', 'column', 'repository'].includes(type)) return 'data';
        if (type === 'external_service') return 'external';
        if (['service', 'provider', 'worker', 'job', 'command'].includes(type)) return 'service';
        if (['component', 'view', 'template', 'hook'].includes(type)) return 'interface';
        return 'code';
    };
    const edgeCategory = (relationship) => {
        const value = String(relationship || '').toLowerCase();
        if (/table|read|write|create|update|delete|persist/.test(value)) return 'data';
        if (/http|route|request|response|middleware|handler/.test(value)) return 'request';
        if (/service|external|provider|client/.test(value)) return 'external';
        return 'code';
    };
    const dominantSemantic = (counts) => {
        const priority = { security: 6, external: 5, data: 4, request: 3, service: 2, interface: 1, code: 0 };
        return Array.from(counts.entries()).sort((left, right) => (right[1] - left[1]) || ((priority[right[0]] || 0) - (priority[left[0]] || 0)))[0]?.[0] || 'code';
    };

    const buildElements = (level) => {
        const groups = new Map();
        const membership = new Map();
        rawNodes.forEach((node) => {
            const value = valueForLevel(node, level);
            const id = idFor(level, value);
            membership.set(Number(node.id), id);
            if (!groups.has(id)) groups.set(id, { id, label: level === 'symbol' ? node.name : value, level, size: 0, confidence: 'low', risk: 'low', type: level === 'symbol' ? node.symbol_type : level, path: level === 'symbol' ? node.path : (level === 'file' ? value : ''), startLine: Number(node.start_line || 1), subsystem: new Set(), architecture: new Set(), feature: new Set(), framework: new Set(), types: new Set(), paths: new Set(), semantics: new Map(), members: [] });
            const group = groups.get(id);
            group.size += 1;
            group.confidence = stronger(group.confidence, node.confidence || 'low', confidenceRank);
            group.risk = stronger(group.risk, node.risk || 'low', riskRank);
            group.subsystem.add(node.subsystem || 'Application core');
            group.architecture.add(node.architecture || 'Application');
            group.feature.add(node.feature || 'Core behavior');
            group.framework.add(node.framework || 'Unspecified');
            group.types.add(node.symbol_type || 'unknown');
            group.paths.add(node.path || '');
            const semantic = semanticFor(node);
            group.semantics.set(semantic, (group.semantics.get(semantic) || 0) + 1);
            group.members.push(Number(node.id));
        });
        const nodes = Array.from(groups.values()).map((group) => ({ data: { ...group, semantic: dominantSemantic(group.semantics), subsystem: Array.from(group.subsystem).join('|'), architecture: Array.from(group.architecture).join('|'), feature: Array.from(group.feature).join('|'), framework: Array.from(group.framework).join('|'), types: Array.from(group.types).join('|'), paths: Array.from(group.paths).join('|'), members: group.members.join('|'), semantics: undefined } }));
        const edges = new Map();
        rawEdges.forEach((edge) => {
            const source = membership.get(Number(edge.source_symbol_id));
            const target = membership.get(Number(edge.target_symbol_id));
            if (!source || !target || source === target) return;
            const key = `${source}|${target}|${edge.relationship_type}`;
            if (!edges.has(key)) edges.set(key, { id: `e${edges.size}-${source}-${target}`, source, target, relationship: edge.relationship_type, category: edgeCategory(edge.relationship_type), confidence: edge.confidence || 'low', count: 0, evidencePath: edge.evidence_path, evidenceLine: Number(edge.evidence_line_start || 1) });
            const item = edges.get(key);
            item.count += 1;
            item.confidence = stronger(item.confidence, edge.confidence || 'low', confidenceRank);
        });
        return [...nodes, ...Array.from(edges.values()).map((data) => ({ data }))];
    };

    const layout = () => cy.layout({ name: ['architecture', 'subsystem', 'feature'].includes(controls.level?.value) ? 'circle' : 'cose', animate: false, fit: true, padding: 48, nodeRepulsion: 7800, idealEdgeLength: 110, edgeElasticity: 90, gravity: 0.28, numIter: 700 }).run();
    const rebuild = () => { selectedId = null; cy.elements().remove(); cy.add(buildElements(controls.level?.value || 'architecture')); applyFilters(false); layout(); renderDefaultDetail(); };
    const includesValue = (element, field, value) => value === '' || String(element.data(field) || '').split('|').includes(value);
    const focusedNeighborhood = (selected) => {
        const direction = controls.direction?.value || 'both';
        const requestedDepth = controls.depth?.value || '1';
        const maximumDepth = requestedDepth === 'all' ? Math.max(1, cy.nodes().length) : Math.max(1, Number(requestedDepth) || 1);
        let focused = selected;
        let frontier = selected;
        let previousSize = 0;
        for (let depth = 0; depth < maximumDepth && frontier.length > 0; depth += 1) {
            const next = (direction === 'outgoing' ? frontier.outgoers() : (direction === 'incoming' ? frontier.incomers() : frontier.neighborhood()))
                .filter((element) => !element.hasClass('filtered'));
            focused = focused.union(next);
            frontier = next.nodes();
            if (focused.length === previousSize) break;
            previousSize = focused.length;
        }
        return focused;
    };
    const updateResultCount = () => {
        if (!controls.resultCount) return;
        const shownNodes = cy.nodes().not('.filtered').length;
        const shownEdges = cy.edges().not('.filtered').length;
        const totalNodes = cy.nodes().length;
        const nodeLabel = shownNodes === 1 ? 'node' : 'nodes';
        const edgeLabel = shownEdges === 1 ? 'relationship' : 'relationships';
        controls.resultCount.textContent = `Showing ${shownNodes} of ${totalNodes} ${nodeLabel}, ${shownEdges} ${edgeLabel}`;
    };

    function applyFilters(refit = true) {
        const needle = (controls.search?.value || '').trim().toLowerCase();
        const minimum = confidenceRank[controls.confidence?.value || 'medium'];
        cy.batch(() => {
            cy.elements().removeClass('filtered muted neighbor path-highlight');
            cy.nodes().forEach((node) => {
                const data = node.data();
                const text = `${data.label} ${data.paths} ${data.types}`.toLowerCase();
                const hidden = (needle !== '' && !text.includes(needle)) || (controls.type?.value && !includesValue(node, 'types', controls.type.value)) || (controls.subsystem?.value && !includesValue(node, 'subsystem', controls.subsystem.value)) || (controls.feature?.value && !includesValue(node, 'feature', controls.feature.value)) || (controls.framework?.value && !includesValue(node, 'framework', controls.framework.value)) || (controls.risk?.value && data.risk !== controls.risk.value) || confidenceRank[data.confidence || 'low'] < minimum || (drillFilter && !includesValue(node, drillFilter.field, drillFilter.value));
                node.toggleClass('filtered', Boolean(hidden));
            });
            cy.edges().forEach((edge) => {
                const hidden = edge.source().hasClass('filtered') || edge.target().hasClass('filtered') || confidenceRank[edge.data('confidence') || 'low'] < minimum || (controls.relationship?.value && edge.data('relationship') !== controls.relationship.value);
                edge.toggleClass('filtered', Boolean(hidden));
            });
            if (selectedId) {
                const selected = cy.getElementById(selectedId);
                if (selected.empty() || selected.hasClass('filtered')) {
                    selectedId = null;
                    cy.nodes().unselect();
                    renderDefaultDetail();
                } else {
                    const neighborhood = focusedNeighborhood(selected);
                    cy.elements().not(neighborhood).not('.filtered').addClass('muted');
                    neighborhood.addClass('neighbor');
                }
            }
        });
        updateResultCount();
        if (refit) cy.fit(cy.elements(':visible'), 45);
    }

    const renderDefaultDetail = () => {
        if (!controls.detail) return;
        controls.detail.replaceChildren();
        const title = document.createElement('strong'); title.textContent = 'Select a node';
        const body = document.createElement('p'); body.textContent = 'The chosen direction and depth control which neighbors stay bright. Double-click a group to drill down.';
        controls.detail.append(title, body);
    };
    const renderDetail = (node) => {
        if (!controls.detail) return;
        controls.detail.replaceChildren();
        const data = node.data();
        const title = document.createElement('strong'); title.textContent = data.label;
        const body = document.createElement('p');
        body.textContent = data.level === 'symbol' ? `${String(data.type).replaceAll('_', ' ')} in ${data.path}:${data.startLine}. ${data.confidence} extraction confidence; ${data.risk} edit risk.` : `${data.size} symbols grouped at the ${data.level} level. Double-click to drill deeper.`;
        controls.detail.append(title, body);
        const actions = document.createElement('div');
        actions.className = 'graph-detail-actions';
        if (data.level === 'symbol') {
            const evidenceLink = document.createElement('a');
            evidenceLink.href = `symbol.php?project=${encodeURIComponent(projectId)}&id=${encodeURIComponent(String(data.members))}`;
            evidenceLink.textContent = 'Open evidence';
            const changeLink = document.createElement('a');
            changeLink.href = `change.php?id=${encodeURIComponent(projectId)}&type=symbol&target=${encodeURIComponent(String(data.label))}`;
            changeLink.textContent = 'Plan change';
            actions.append(evidenceLink, changeLink);
        } else {
            const drillButton = document.createElement('button');
            drillButton.type = 'button';
            drillButton.textContent = 'Drill into group';
            drillButton.addEventListener('click', () => drill(node));
            actions.append(drillButton);
        }
        const askLink = document.createElement('a');
        askLink.href = `ask.php?id=${encodeURIComponent(projectId)}`;
        askLink.dataset.askOpen = '';
        askLink.dataset.askQuestion = `What depends on ${String(data.label)}?`;
        askLink.setAttribute('aria-haspopup', 'dialog');
        askLink.setAttribute('aria-controls', 'ask-codebase-panel');
        askLink.textContent = 'Ask codebase';
        actions.append(askLink);
        controls.detail.append(actions);
        const edges = node.connectedEdges(':visible').slice(0, 8);
        if (edges.length) {
            const list = document.createElement('ul');
            edges.forEach((edge) => { const item = document.createElement('li'); item.textContent = `${edge.data('relationship')} · ${edge.data('evidencePath')}:${edge.data('evidenceLine')}`; list.append(item); });
            controls.detail.append(list);
        }
    };

    const drill = (node) => {
        const levels = ['architecture', 'subsystem', 'feature', 'file', 'symbol'];
        const current = controls.level?.value || 'architecture';
        const index = levels.indexOf(current);
        if (index < 0 || index === levels.length - 1) return;
        drillFilter = current === 'file' ? { field: 'paths', value: node.data('label') } : { field: current, value: node.data('label') };
        controls.level.value = levels[index + 1];
        rebuild();
    };

    cy.on('tap', 'node', (event) => {
        const node = event.target;
        const now = Date.now();
        if (lastTap.id === node.id() && now - lastTap.at < 380) { drill(node); lastTap = { id: '', at: 0 }; return; }
        lastTap = { id: node.id(), at: now };
        selectedId = selectedId === node.id() ? null : node.id();
        cy.nodes().unselect();
        if (selectedId) { node.select(); renderDetail(node); } else renderDefaultDetail();
        applyFilters(false);
    });
    cy.on('tap', (event) => { if (event.target !== cy) return; selectedId = null; cy.nodes().unselect(); renderDefaultDetail(); applyFilters(false); });

    const tracePath = () => {
        const from = controls.from?.value || '';
        const to = controls.to?.value || '';
        if (!from || !to || from === to) { if (controls.pathResult) controls.pathResult.textContent = 'Choose two different symbols.'; return; }
        controls.level.value = 'symbol'; drillFilter = null; rebuild();
        const root = cy.getElementById(`n${from}`);
        const target = cy.getElementById(`n${to}`);
        const result = cy.elements().dijkstra({ root, directed: false, weight: (edge) => ({ high: 1, medium: 2, low: 5 }[edge.data('confidence')] || 5) });
        const distance = result.distanceTo(target);
        if (!Number.isFinite(distance)) { if (controls.pathResult) controls.pathResult.textContent = 'No connected static path was found.'; return; }
        const path = result.pathTo(target);
        path.removeClass('filtered muted').addClass('path-highlight');
        cy.elements().not(path).addClass('muted');
        cy.fit(path, 70);
        if (controls.pathResult) controls.pathResult.textContent = `${path.nodes().length} nodes · confidence-weighted cost ${distance}`;
    };

    controls.level?.addEventListener('change', () => { drillFilter = null; rebuild(); });
    controls.search?.addEventListener('input', () => applyFilters());
    [controls.type, controls.relationship, controls.subsystem, controls.feature, controls.framework, controls.risk, controls.confidence, controls.direction, controls.depth].forEach((control) => control?.addEventListener('change', () => applyFilters()));
    controls.fit?.addEventListener('click', () => cy.fit(cy.elements(':visible'), 45));
    controls.trace?.addEventListener('click', tracePath);
    controls.reset?.addEventListener('click', () => {
        [controls.search, controls.type, controls.relationship, controls.subsystem, controls.feature, controls.framework, controls.risk].forEach((control) => { if (control) control.value = ''; });
        if (controls.confidence) controls.confidence.value = 'medium';
        if (controls.level) controls.level.value = 'architecture';
        if (controls.direction) controls.direction.value = 'both';
        if (controls.depth) controls.depth.value = '1';
        if (controls.pathResult) controls.pathResult.textContent = '';
        drillFilter = null; rebuild();
    });
    rebuild();
})();
