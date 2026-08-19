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
            { selector: 'node[level = "feature"]', style: { 'background-color': '#332a1b', 'border-color': '#d2a84b', width: 'mapData(size, 1, 30, 100, 180)', height: 58 } },
            { selector: 'node[risk = "high"]', style: { 'border-color': '#e2747b', 'border-width': 2.5 } },
            { selector: 'node:selected', style: { 'background-color': '#1f513e', 'border-color': '#a2f5c8', 'border-width': 3 } },
            { selector: 'edge', style: { width: 'mapData(count, 1, 12, 1, 5)', 'line-color': '#45645f', 'target-arrow-color': '#45645f', 'target-arrow-shape': 'triangle', 'curve-style': 'bezier', opacity: 0.72, 'arrow-scale': 0.7 } },
            { selector: 'edge[confidence = "high"]', style: { 'line-color': '#70c99f', 'target-arrow-color': '#70c99f' } },
            { selector: '.muted', style: { opacity: 0.08 } },
            { selector: '.neighbor', style: { opacity: 1, 'border-color': '#77d7a9' } },
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

    const buildElements = (level) => {
        const groups = new Map();
        const membership = new Map();
        rawNodes.forEach((node) => {
            const value = valueForLevel(node, level);
            const id = idFor(level, value);
            membership.set(Number(node.id), id);
            if (!groups.has(id)) groups.set(id, { id, label: level === 'symbol' ? node.name : value, level, size: 0, confidence: 'low', risk: 'low', type: level === 'symbol' ? node.symbol_type : level, path: level === 'symbol' ? node.path : (level === 'file' ? value : ''), startLine: Number(node.start_line || 1), subsystem: new Set(), architecture: new Set(), feature: new Set(), framework: new Set(), types: new Set(), paths: new Set(), members: [] });
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
            group.members.push(Number(node.id));
        });
        const nodes = Array.from(groups.values()).map((group) => ({ data: { ...group, subsystem: Array.from(group.subsystem).join('|'), architecture: Array.from(group.architecture).join('|'), feature: Array.from(group.feature).join('|'), framework: Array.from(group.framework).join('|'), types: Array.from(group.types).join('|'), paths: Array.from(group.paths).join('|'), members: group.members.join('|') } }));
        const edges = new Map();
        rawEdges.forEach((edge) => {
            const source = membership.get(Number(edge.source_symbol_id));
            const target = membership.get(Number(edge.target_symbol_id));
            if (!source || !target || source === target) return;
            const key = `${source}|${target}|${edge.relationship_type}`;
            if (!edges.has(key)) edges.set(key, { id: `e${edges.size}-${source}-${target}`, source, target, relationship: edge.relationship_type, confidence: edge.confidence || 'low', count: 0, evidencePath: edge.evidence_path, evidenceLine: Number(edge.evidence_line_start || 1) });
            const item = edges.get(key);
            item.count += 1;
            item.confidence = stronger(item.confidence, edge.confidence || 'low', confidenceRank);
        });
        return [...nodes, ...Array.from(edges.values()).map((data) => ({ data }))];
    };

    const layout = () => cy.layout({ name: ['architecture', 'subsystem', 'feature'].includes(controls.level?.value) ? 'circle' : 'cose', animate: false, fit: true, padding: 48, nodeRepulsion: 7800, idealEdgeLength: 110, edgeElasticity: 90, gravity: 0.28, numIter: 700 }).run();
    const rebuild = () => { selectedId = null; cy.elements().remove(); cy.add(buildElements(controls.level?.value || 'architecture')); applyFilters(false); layout(); renderDefaultDetail(); };
    const includesValue = (element, field, value) => value === '' || String(element.data(field) || '').split('|').includes(value);

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
                const neighborhood = selected.closedNeighborhood().filter(':visible');
                cy.elements().not(neighborhood).not('.filtered').addClass('muted');
                neighborhood.addClass('neighbor');
            }
        });
        if (refit) cy.fit(cy.elements(':visible'), 45);
    }

    const renderDefaultDetail = () => {
        if (!controls.detail) return;
        controls.detail.replaceChildren();
        const title = document.createElement('strong'); title.textContent = 'Select a node';
        const body = document.createElement('p'); body.textContent = 'Neighbors stay bright. Double-click a group to drill down.';
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
        if (data.level === 'symbol') {
            const link = document.createElement('a'); link.href = `symbol.php?project=${encodeURIComponent(projectId)}&id=${encodeURIComponent(String(data.members))}`; link.textContent = 'Open symbol evidence'; controls.detail.append(link);
        }
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
    [controls.type, controls.relationship, controls.subsystem, controls.feature, controls.framework, controls.risk, controls.confidence].forEach((control) => control?.addEventListener('change', () => applyFilters()));
    controls.fit?.addEventListener('click', () => cy.fit(cy.elements(':visible'), 45));
    controls.trace?.addEventListener('click', tracePath);
    controls.reset?.addEventListener('click', () => {
        [controls.search, controls.type, controls.relationship, controls.subsystem, controls.feature, controls.framework, controls.risk].forEach((control) => { if (control) control.value = ''; });
        if (controls.confidence) controls.confidence.value = 'medium';
        if (controls.level) controls.level.value = 'architecture';
        if (controls.pathResult) controls.pathResult.textContent = '';
        drillFilter = null; rebuild();
    });
    rebuild();
})();
