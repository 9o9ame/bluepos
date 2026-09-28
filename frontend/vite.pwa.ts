import fs from 'node:fs'
import path from 'node:path'
import type { Plugin, ResolvedConfig } from 'vite'

const MANIFEST = {
  name: 'BluePOS',
  short_name: 'BluePOS',
  description: 'BluePOS desktop point of sale',
  start_url: '/',
  scope: '/',
  display: 'standalone',
  orientation: 'any',
  background_color: '#d9dee6',
  theme_color: '#1f4e79',
  lang: 'en',
  icons: [
    {
      src: '/app-icons/icon-192.png',
      sizes: '192x192',
      type: 'image/png',
      purpose: 'any',
    },
    {
      src: '/app-icons/icon-512.png',
      sizes: '512x512',
      type: 'image/png',
      purpose: 'any',
    },
    {
      src: '/app-icons/icon-512-maskable.png',
      sizes: '512x512',
      type: 'image/png',
      purpose: 'maskable',
    },
  ],
} as const

function buildServiceWorker(precacheUrls: string[]): string {
  const urls = JSON.stringify(precacheUrls)
  return `/* BluePOS shell service worker — installability only. No API caching. */
const CACHE_NAME = 'bluepos-shell-v1';
const PRECACHE = ${urls};

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))),
    ).then(() => self.clients.claim()),
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => response)
        .catch(() => caches.match('/index.html')),
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(request).then((response) => {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        const clone = response.clone();
        const pathname = url.pathname;
        const isShellAsset =
          pathname.startsWith('/assets/') ||
          pathname.startsWith('/app-icons/') ||
          pathname === '/manifest.webmanifest';
        if (isShellAsset) {
          void caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      });
    }),
  );
});
`
}

/**
 * Lightweight PWA support (manifest + shell SW) without workbox-build.
 * Compatible with Node 18. Does not cache authenticated API traffic.
 */
export function blueposPwa(): Plugin {
  let config: ResolvedConfig
  let distAssets: string[] = []

  return {
    name: 'bluepos-pwa',
    apply: 'build',
    configResolved(resolved) {
      config = resolved
    },
    transformIndexHtml(html) {
      if (!html.includes('rel="manifest"')) {
        return html.replace(
          '</head>',
          '    <link rel="manifest" href="/manifest.webmanifest" />\n  </head>',
        )
      }
      return html
    },
    generateBundle(_options, bundle) {
      distAssets = Object.keys(bundle)
        .filter((fileName) => !fileName.endsWith('.map'))
        .map((fileName) => `/${fileName.replace(/\\/g, '/')}`)
    },
    closeBundle() {
      const outDir = path.resolve(config.root, config.build.outDir)
      const precache = Array.from(
        new Set([
          '/',
          '/index.html',
          '/manifest.webmanifest',
          '/app-icons/favicon.svg',
          '/app-icons/icon-192.png',
          '/app-icons/icon-512.png',
          '/app-icons/icon-512-maskable.png',
          ...distAssets,
        ]),
      )

      fs.writeFileSync(
        path.join(outDir, 'manifest.webmanifest'),
        `${JSON.stringify(MANIFEST, null, 2)}\n`,
        'utf8',
      )
      fs.writeFileSync(path.join(outDir, 'sw.js'), buildServiceWorker(precache), 'utf8')
    },
  }
}
