// Ambient typing for Vite's `import.meta.env` without depending on `vite/client`
// (this package does not declare vite as a dependency; pnpm's strict node_modules
// would not resolve the triple-slash reference). Mirrors the shape the runtime
// code reads — only VITE_*-style string env vars.
interface ImportMetaEnv {
  readonly [key: string]: string | undefined
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
