-- The bootstrap upload table was a one-time transport workaround. It exposed
-- build chunks to the anon Data API role and is not part of WTFCode runtime.
DROP TABLE IF EXISTS public._wtfcode_build_chunks;
