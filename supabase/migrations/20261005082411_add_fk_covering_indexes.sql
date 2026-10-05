-- Add covering indexes for foreign keys flagged by the Supabase database advisor.
CREATE INDEX IF NOT EXISTS architecture_edges_from_node_id_index
    ON public.architecture_edges (from_node_id);
CREATE INDEX IF NOT EXISTS architecture_edges_to_node_id_index
    ON public.architecture_edges (to_node_id);
CREATE INDEX IF NOT EXISTS change_guard_snapshots_scan_run_id_index
    ON public.change_guard_snapshots (scan_run_id);
CREATE INDEX IF NOT EXISTS change_guard_snapshots_user_id_index
    ON public.change_guard_snapshots (user_id);
CREATE INDEX IF NOT EXISTS code_routes_file_id_index
    ON public.code_routes (file_id);
CREATE INDEX IF NOT EXISTS code_symbols_parent_symbol_id_index
    ON public.code_symbols (parent_symbol_id);
CREATE INDEX IF NOT EXISTS symbol_relationships_scan_run_id_index
    ON public.symbol_relationships (scan_run_id);
