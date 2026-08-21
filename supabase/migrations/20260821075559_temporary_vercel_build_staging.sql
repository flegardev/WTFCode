CREATE TABLE IF NOT EXISTS public._wtfcode_build_chunks (
  part integer PRIMARY KEY,
  data text NOT NULL
);

ALTER TABLE public._wtfcode_build_chunks ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public._wtfcode_build_chunks FROM public, authenticated, service_role;
GRANT SELECT ON public._wtfcode_build_chunks TO anon;
DROP POLICY IF EXISTS wtfcode_build_public_read ON public._wtfcode_build_chunks;
CREATE POLICY wtfcode_build_public_read
ON public._wtfcode_build_chunks
FOR SELECT TO anon
USING (true);;
