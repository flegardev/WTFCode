(() => {
  "use strict";

  const dataNode = document.getElementById("overview-architecture-data");
  const viewport = document.getElementById("overview-architecture-graph");
  const detail = document.getElementById("overview-architecture-detail");
  if (!dataNode || !viewport || typeof window.cytoscape !== "function") return;

  let graph;
  try {
    graph = JSON.parse(dataNode.textContent || "{}");
  } catch {
    return;
  }

  const rawNodes = Array.isArray(graph.nodes) ? graph.nodes : [];
  const rawEdges = Array.isArray(graph.edges) ? graph.edges : [];
  const nodeBase = viewport.dataset.nodeBase || "";
  const knownKeys = new Set(
    rawNodes.map((node) => String(node.key || "")).filter(Boolean),
  );

  const categoryFor = (node) => {
    const signal =
      `${String(node.type || "")} ${String(node.key || "")} ${String(node.label || "")}`.toLowerCase();
    if (/auth|identity|security|session|permission/.test(signal))
      return "security";
    if (/database|data|model|storage|table|queue/.test(signal)) return "data";
    if (/external|integration|service|provider|api client/.test(signal))
      return "external";
    if (/view|ui|front|client|interface/.test(signal)) return "interface";
    return "application";
  };

  const elements = [
    ...rawNodes
      .filter((node) => knownKeys.has(String(node.key || "")))
      .map((node) => ({
        data: {
          id: String(node.key),
          label: String(node.label || node.key),
          type: String(node.type || "system"),
          explanation: String(
            node.explanation ||
              "No plain-language explanation was stored for this system.",
          ),
          category: categoryFor(node),
        },
      })),
    ...rawEdges
      .filter(
        (edge) =>
          knownKeys.has(String(edge.from || "")) &&
          knownKeys.has(String(edge.to || "")),
      )
      .map((edge, index) => ({
        data: {
          id: `overview-edge-${index}`,
          source: String(edge.from),
          target: String(edge.to),
          relationship: String(edge.relationship || "connects to"),
        },
      })),
  ];

  if (viewport.clientWidth < 340)
    viewport.style.height = `${Math.min(480, Math.max(160, rawNodes.length * 82))}px`;
  const cy = window.cytoscape({
    container: viewport,
    elements,
    minZoom: 0.25,
    maxZoom: 2.2,
    boxSelectionEnabled: false,
    style: [
      {
        selector: "node",
        style: {
          width: 166,
          height: 58,
          shape: "round-rectangle",
          label: "data(label)",
          color: "#edf6f2",
          "font-family": '"Segoe UI Variable Text", "Aptos", sans-serif',
          "font-size": 13,
          "font-weight": 700,
          "text-wrap": "ellipsis",
          "text-max-width": 150,
          "text-valign": "center",
          "text-halign": "center",
          "background-color": "#25213f",
          "border-color": "#b5a7ff",
          "border-width": 1.5,
          "overlay-opacity": 0,
        },
      },
      {
        selector: 'node[category = "interface"]',
        style: { "background-color": "#142d3d", "border-color": "#65bde8" },
      },
      {
        selector: 'node[category = "data"]',
        style: { "background-color": "#352b19", "border-color": "#e2b561" },
      },
      {
        selector: 'node[category = "external"]',
        style: { "background-color": "#12343a", "border-color": "#63cfda" },
      },
      {
        selector: 'node[category = "security"]',
        style: { "background-color": "#372126", "border-color": "#ef8585" },
      },
      {
        selector: "node:selected",
        style: {
          "border-width": 3,
          "border-color": "#d5ffe8",
          "background-color": "#24523f",
        },
      },
      {
        selector: "edge",
        style: {
          "line-style": "dashed",
          width: 1.5,
          "line-color": "#55716b",
          "target-arrow-color": "#55716b",
          "target-arrow-shape": "triangle",
          "curve-style": "bezier",
          opacity: 0.82,
          "arrow-scale": 0.75,
          label: "data(relationship)",
          color: "#94a9a3",
          "font-size": 11,
          "text-background-color": "#0b1114",
          "text-background-opacity": 0.88,
          "text-background-padding": 3,
          "text-rotation": 0,
        },
      },
      { selector: ".is-dimmed", style: { opacity: 0.55 } },
      { selector: ".is-neighbor", style: { opacity: 1 } },
    ],
    layout: {
      name: "grid",
      cols: viewport.clientWidth < 340 ? 1 : rawNodes.length <= 4 ? 2 : 3,
      padding: 24,
      avoidOverlap: true,
      animate: false,
    },
  });

  const renderDetail = (node) => {
    if (!detail) return;
    detail.replaceChildren();
    const title = document.createElement("strong");
    title.textContent = String(node.data("label"));
    const explanation = document.createElement("span");
    explanation.textContent = String(node.data("explanation"));
    const link = document.createElement("a");
    link.href = `${nodeBase}${encodeURIComponent(String(node.id()))}`;
    link.textContent = "Open system evidence";
    const relationships = document.createElement("p");
    const connected = rawEdges.filter(
      (edge) => edge.from === node.id() || edge.to === node.id(),
    );
    relationships.textContent = connected.length
      ? connected
          .map(
            (edge) =>
              `${edge.from} → ${edge.to}: ${edge.relationship} (inferred)`,
          )
          .join("; ")
      : "No architecture connection was inferred for this system.";
    const caution = document.createElement("small");
    caution.textContent =
      "Before changing this part, inspect its source dependencies. The diagram cannot predict breakage.";
    detail.append(title, explanation, relationships, caution, link);
  };

  const selectNode = (selected) => {
    cy.elements().removeClass("is-dimmed is-neighbor");
    const neighborhood = selected.closedNeighborhood();
    cy.elements().not(neighborhood).addClass("is-dimmed");
    neighborhood.addClass("is-neighbor");
    selected.select();
    renderDetail(selected);
  };
  cy.on("tap", "node", (event) => selectNode(event.target));
  document
    .querySelectorAll("[data-system-key]")
    .forEach((button) =>
      button.addEventListener("click", () =>
        selectNode(cy.getElementById(button.dataset.systemKey)),
      ),
    );

  cy.on("tap", (event) => {
    if (event.target !== cy) return;
    cy.elements().removeClass("is-dimmed is-neighbor");
    cy.nodes().unselect();
  });

  const fit = () => {
    cy.resize();
    cy.fit(undefined, 24);
    if (cy.zoom() > 1.15)
      cy.zoom({
        level: 1.15,
        renderedPosition: {
          x: viewport.clientWidth / 2,
          y: viewport.clientHeight / 2,
        },
      });
    cy.center();
  };
  let lastLayoutWidth = 0;
  const layoutVisible = () => {
    const width = viewport.clientWidth;
    if (!width) return;
    lastLayoutWidth = width;
    const columns = width < 340 ? 1 : rawNodes.length <= 4 ? 2 : 3;
    viewport.style.height =
      columns === 1 ? `${Math.max(180, rawNodes.length * 90 + 32)}px` : "260px";
    cy.resize();
    const mobileOrder = [
      ...new Set([
        ...rawEdges.flatMap((edge) => [String(edge.from), String(edge.to)]),
        ...rawNodes.map((node) => String(node.key)),
      ]),
    ];
    const position = (node) => ({
      row: Math.floor(mobileOrder.indexOf(node.id()) / columns),
      col: mobileOrder.indexOf(node.id()) % columns,
    });
    cy.layout({
      name: "grid",
      cols: columns,
      position: columns === 1 ? position : () => undefined,
      padding: 20,
      avoidOverlap: true,
      animate: false,
    }).run();
    cy.style()
      .selector("edge")
      .style({ label: columns === 1 ? "" : "data(relationship)" })
      .update();
    fit();
  };
  document.querySelector("[data-diagram-fit]")?.addEventListener("click", fit);
  document.querySelectorAll("[data-diagram-zoom]").forEach((button) =>
    button.addEventListener("click", () =>
      cy.zoom({
        level: Math.min(
          2.2,
          Math.max(
            0.25,
            cy.zoom() * (button.dataset.diagramZoom === "in" ? 1.2 : 1 / 1.2),
          ),
        ),
        renderedPosition: {
          x: viewport.clientWidth / 2,
          y: viewport.clientHeight / 2,
        },
      }),
    ),
  );
  const themeGraph = () => {
    const light = document.documentElement.dataset.theme === "light";
    cy.style()
      .selector("node")
      .style({
        color: light ? "#20243b" : "#f0f3fc",
        "background-color": light ? "#ede9fc" : "#25213f",
      })
      .selector("edge")
      .style({
        color: light ? "#45516c" : "#b2bdd2",
        "line-color": light ? "#6c7188" : "#8290ae",
        "target-arrow-color": light ? "#6c7188" : "#8290ae",
        "text-background-color": light ? "#ffffff" : "#101724",
      })
      .update();
    cy.style()
      .selector("node[category]")
      .style({ "background-color": light ? "#ede9fc" : "#25213f" })
      .update();
  };
  themeGraph();
  const themeObserver = new MutationObserver(themeGraph);
  themeObserver.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["data-theme"],
  });
  fit();
  document
    .querySelector("[data-diagram-toggle]")
    ?.addEventListener("click", (event) => {
      const visuals = document.getElementById("diagram-visuals");
      const opened = visuals.classList.toggle("is-open");
      event.currentTarget.setAttribute("aria-expanded", String(opened));
      event.currentTarget.textContent = opened
        ? "Close interactive diagram"
        : "Open interactive diagram";
      if (opened) {
        layoutVisible();
      }
    });
  if (typeof ResizeObserver === "function") {
    const observer = new ResizeObserver(() => {
      if (viewport.clientWidth && viewport.clientWidth !== lastLayoutWidth)
        layoutVisible();
    });
    observer.observe(viewport);
    window.addEventListener("pagehide", () => observer.disconnect(), {
      once: true,
    });
  }
})();
