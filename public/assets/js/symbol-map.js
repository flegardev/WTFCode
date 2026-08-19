(() => {
    'use strict';

    const dataNode = document.getElementById('symbol-graph-data');
    const viewport = document.getElementById('graph-viewport');
    const nodeLayer = document.getElementById('graph-nodes');
    const edgeLayer = document.getElementById('graph-edges');
    if (!dataNode || !viewport || !nodeLayer || !edgeLayer) return;

    let graph;
    try {
        graph = JSON.parse(dataNode.textContent || '{}');
    } catch {
        return;
    }

    const projectId = viewport.closest('[data-project]')?.dataset.project || '';
    const elements = new Map(Array.from(nodeLayer.querySelectorAll('.graph-node')).map((element) => [Number(element.dataset.id), element]));
    const positions = new Map();
    const confidenceRank = { low: 1, medium: 2, high: 3 };
    const columnFor = (type) => {
        if (['route_handler', 'controller', 'middleware'].includes(type)) return 0;
        if (['component', 'hook', 'function', 'method', 'class', 'server_action'].includes(type)) return 1;
        if (['table', 'model', 'schema', 'column', 'view'].includes(type)) return 2;
        if (['external_service', 'environment_variable'].includes(type)) return 3;
        return 1;
    };

    const columns = [[], [], [], []];
    graph.nodes.forEach((node) => columns[columnFor(node.symbol_type)].push(node));
    const canvasWidth = 1420;
    const canvasHeight = Math.max(760, ...columns.map((column) => column.length * 108 + 80));
    columns.forEach((column, columnIndex) => {
        column.forEach((node, rowIndex) => {
            positions.set(Number(node.id), { x: 38 + columnIndex * 345, y: 42 + rowIndex * 108 });
        });
    });

    nodeLayer.classList.add('is-enhanced');
    nodeLayer.style.width = `${canvasWidth}px`;
    nodeLayer.style.height = `${canvasHeight}px`;
    edgeLayer.setAttribute('viewBox', `0 0 ${canvasWidth} ${canvasHeight}`);
    edgeLayer.setAttribute('width', String(canvasWidth));
    edgeLayer.setAttribute('height', String(canvasHeight));
    elements.forEach((element, id) => {
        const position = positions.get(id);
        if (!position) return;
        element.style.transform = `translate(${position.x}px, ${position.y}px)`;
    });

    const svgNamespace = 'http://www.w3.org/2000/svg';
    const edgeElements = [];
    graph.edges.forEach((edge) => {
        const source = positions.get(Number(edge.source_symbol_id));
        const target = positions.get(Number(edge.target_symbol_id));
        if (!source || !target) return;
        const path = document.createElementNS(svgNamespace, 'path');
        const startX = source.x + 272;
        const startY = source.y + 38;
        const endX = target.x;
        const endY = target.y + 38;
        const bend = Math.max(55, Math.abs(endX - startX) * 0.45);
        path.setAttribute('d', `M ${startX} ${startY} C ${startX + bend} ${startY}, ${endX - bend} ${endY}, ${endX} ${endY}`);
        path.classList.add('graph-edge', `confidence-${edge.confidence}`);
        path.dataset.source = String(edge.source_symbol_id);
        path.dataset.target = String(edge.target_symbol_id);
        path.dataset.relationship = edge.relationship_type;
        edgeLayer.appendChild(path);
        edgeElements.push({ data: edge, element: path });
    });

    const search = document.getElementById('graph-search');
    const type = document.getElementById('graph-type');
    const confidence = document.getElementById('graph-confidence');
    const reset = document.getElementById('graph-reset');
    const detail = document.getElementById('graph-detail');
    let selected = null;

    const matchingIds = () => {
        const needle = (search?.value || '').trim().toLowerCase();
        const requestedType = type?.value || '';
        const minimum = confidenceRank[confidence?.value || 'medium'];
        const ids = new Set();
        graph.nodes.forEach((node) => {
            const textMatches = needle === '' || node.name.toLowerCase().includes(needle) || node.path.toLowerCase().includes(needle);
            if (textMatches && (requestedType === '' || node.symbol_type === requestedType) && confidenceRank[node.confidence] >= minimum) ids.add(Number(node.id));
        });
        return ids;
    };

    const renderDetail = (id) => {
        if (!detail) return;
        detail.replaceChildren();
        const node = graph.nodes.find((candidate) => Number(candidate.id) === id);
        if (!node) return;
        const title = document.createElement('strong');
        title.textContent = node.name;
        const meta = document.createElement('p');
        meta.textContent = `${node.symbol_type.replaceAll('_', ' ')} in ${node.path}:${node.start_line}. ${node.confidence} extraction confidence.`;
        const link = document.createElement('a');
        link.href = `symbol.php?project=${encodeURIComponent(projectId)}&id=${encodeURIComponent(String(id))}`;
        link.textContent = 'Open symbol evidence';
        detail.append(title, meta, link);
        const connected = edgeElements.filter((edge) => Number(edge.data.source_symbol_id) === id || Number(edge.data.target_symbol_id) === id).slice(0, 6);
        if (connected.length > 0) {
            const list = document.createElement('ul');
            connected.forEach((edge) => {
                const item = document.createElement('li');
                item.textContent = `${edge.data.relationship_type} at ${edge.data.evidence_path}:${edge.data.evidence_line_start}`;
                list.appendChild(item);
            });
            detail.appendChild(list);
        }
    };

    const applyFilters = () => {
        const visible = matchingIds();
        let related = null;
        if (selected !== null) {
            related = new Set([selected]);
            edgeElements.forEach(({ data }) => {
                if (Number(data.source_symbol_id) === selected) related.add(Number(data.target_symbol_id));
                if (Number(data.target_symbol_id) === selected) related.add(Number(data.source_symbol_id));
            });
        }
        elements.forEach((element, id) => {
            const show = visible.has(id);
            element.hidden = !show;
            element.classList.toggle('is-selected', id === selected);
            element.classList.toggle('is-muted', show && related !== null && !related.has(id));
        });
        edgeElements.forEach(({ data, element }) => {
            const source = Number(data.source_symbol_id);
            const target = Number(data.target_symbol_id);
            const show = visible.has(source) && visible.has(target) && confidenceRank[data.confidence] >= confidenceRank[confidence?.value || 'medium'];
            element.hidden = !show;
            element.classList.toggle('is-related', selected !== null && (source === selected || target === selected));
            element.classList.toggle('is-muted', selected !== null && source !== selected && target !== selected);
        });
    };

    elements.forEach((element, id) => {
        element.addEventListener('click', () => {
            selected = selected === id ? null : id;
            if (selected === null && detail) {
                detail.replaceChildren();
                const title = document.createElement('strong');
                title.textContent = 'Select a symbol';
                const body = document.createElement('p');
                body.textContent = 'Connected nodes and edges stay bright. Every edge keeps its evidence file and line.';
                detail.append(title, body);
            } else if (selected !== null) {
                renderDetail(selected);
            }
            applyFilters();
        });
    });
    [search, type, confidence].forEach((control) => control?.addEventListener(control === search ? 'input' : 'change', applyFilters));
    reset?.addEventListener('click', () => {
        if (search) search.value = '';
        if (type) type.value = '';
        if (confidence) confidence.value = 'medium';
        selected = null;
        if (detail) detail.innerHTML = '<strong>Select a symbol</strong><p>Connected nodes and edges stay bright. Every edge keeps its evidence file and line.</p>';
        applyFilters();
    });
    applyFilters();
})();
