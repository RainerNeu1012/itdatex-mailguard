import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'build',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    rollupOptions: {
      input: {
        portal: resolve('assets/portal/main.jsx'),
      },
      output: {
        entryFileNames: 'portal/[name]-[hash].js',
        chunkFileNames: 'portal/[name]-[hash].js',
        assetFileNames: 'portal/[name]-[hash][extname]',
      },
    },
  },
});
