(() => {
    'use strict';

    const dataNode = document.getElementById('overview-architecture-data');
    const viewport = document.getElementById('overview-architecture-graph');
    const detail = document.getElementById('overview-architecture-detail');
    if (!dataNode || !viewport || typeof window.cytoscape !== 'function') return;

    let graph;
    try { graph = JSON.parse(dataNode.textContent || '{}'); } catch { return; }

    const rawNodes = Array.isArray(graph.nodes) ? graph.nodes : [];
    const rawEdges = Array.isArray(graph.edges) ? graph.edges : [];
    const nodeBase = viewport.dataset.nodeBase || '';
    const knownKeys = new Set(rawNodes.map((node) => String(node.key || '')).filter(Boolean));

    const categoryFor = (node) => {
        const signal = `${String(node.type || '')} ${String(node.key || '')} ${String(node.label || '')}`.toLowerCase();
        if (/auth|identity|security|session|permission/.test(signal)) return 'security';
        if (/database|data|model|storage|table|queue/.test(signal)) return 'data';
        if (/external|integration|service|provider|api client/.test(signal)) return 'external';
        if (/view|ui|front|client|interface/.test(signal)) return 'interface';
        return 'application';
    };

    const elements = [
        ...rawNodes
            .filter((node) => knownKeys.has(String(node.key || '')))
            .map((node) => ({ data: {
                id: String(node.key),
                label: String(node.label || node.key),
                type: String(node.type || 'system'),
                explanation: String(node.explanation || 'No plain-language explanation was stored for this system.'),
                category: categoryFor(node),
            } })),
        ...rawEdges
            .filter((edge) => knownKeys.has(String(edge.from || '')) && knownKeys.has(String(edge.to || '')))
            .map((edge, index) => ({ data: {
                id: `overview-edge-${index}`,
                source: String(edge.from),
                target: String(edge.to),
                relationship: String(edge.relationship || 'connects to'),
            } })),
    ];

    const cy = window.cytoscape({
        container: viewport,
        elements,
        minZoom: 0.45,
        maxZoom: 2.2,
        boxSelectionEnabled: false,
        style: [
            { selector: 'node', style: { width: 138, height: 48, shape: 'round-rectangle', label: 'data(label)', color: '#edf6f2', 'font-family': '"Segoe UI Variable Text", "Aptos", sans-serif', 'font-size': 11, 'font-weight': 700, 'text-wrap': 'ellipsis', 'text-max-width': 118, 'text-valign': 'center', 'text-halign': 'center', 'background-color': '#193127', 'border-color': '#73d9a9', 'border-width': 1.5, 'overlay-opacity': 0 } },
            { selector: 'node[category = "interface"]', style: { 'background-color': '#142d3d', 'border-color': '#65bde8' } },
            { selector: 'node[category = "data"]', style: { 'background-color': '#352b19', 'border-color': '#e2b561' } },
            { selector: 'node[category = "external"]', style: { 'background-color': '#12343a', 'border-color': '#63cfda' } },
            { selector: 'node[category = "security"]', style: { 'background-color': '#372126', 'border-color': '#ef8585' } },
            { selector: 'node:selected', style: { 'border-width': 3, 'border-color': '#d5ffe8', 'background-color': '#24523f' } },
            { selector: 'edge', style: { width: 1.5, 'line-color': '#55716b', 'target-arrow-color': '#55716b', 'target-arrow-shape': 'triangle', 'curve-style': 'bezier', opacity: 0.82, 'arrow-scale': 0.75, label: 'data(relationship)', color: '#94a9a3', 'font-size': 8, 'text-background-color': '#0b1114', 'text-background-opacity': 0.88, 'text-background-padding': 3, 'text-rotation': 'autorotate' } },
            { selector: '.is-dimmed', style: { opacity: 0.16 } },
            { selector: '.is-neighbor', style: { opacity: 1 } },
        ],
        layout: rawEdges.length > 0
            ? { name: 'breadthfirst', directed: true, spacingFactor: 1.25, padding: 28, animate: false }
            : { name: 'circle', padding: 30, animate: false },
    });

    const renderDetail = (node) => {
        if (!detail) return;
        detail.replaceChildren();
        const title = document.createElement('strong');
        title.textContent = String(node.data('label'));
        const explanation = document.createElement('span');
        explanation.textContent = String(node.data('explanation'));
        const link = document.createElement('a');
        link.href = `${nodeBase}${encodeURIComponent(String(node.id()))}`;
        link.textContent = 'Open system evidence';
        detail.append(title, explanation, link);
    };

    cy.on('tap', 'node', (event) => {
        const selected = event.target;
        cy.elements().removeClass('is-dimmed is-neighbor');
        const neighborhood = selected.closedNeighborhood();
        cy.elements().not(neighborhood).addClass('is-dimmed');
        neighborhood.addClass('is-neighbor');
        renderDetail(selected);
    });

    cy.on('tap', (event) => {
        if (event.target !== cy) return;
        cy.elements().removeClass('is-dimmed is-neighbor');
        cy.nodes().unselect();
    });

    if (typeof ResizeObserver === 'function') {
        const observer = new ResizeObserver(() => {
            cy.resize();
            cy.fit(undefined, 24);
        });
        observer.observe(viewport);
        window.addEventListener('pagehide', () => observer.disconnect(), { once: true });
    }
})();
