import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  distDir: process.env.FLOWMV_DIST_DIR ?? ".next",
  async headers() {
    return [
      {
        source: "/invitations/accept",
        headers: [
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "Cache-Control", value: "no-store, private" },
          { key: "Pragma", value: "no-cache" },
          { key: "X-Robots-Tag", value: "noindex, nofollow" },
        ],
      },
      {
        source: "/verify-email/:path*",
        headers: [
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "Cache-Control", value: "no-store, private" },
          { key: "X-Robots-Tag", value: "noindex, nofollow" },
        ],
      },
    ];
  },
  experimental: {
    agentFeedback: true,
    ...(process.env.FLOWMV_BUILD_SINGLE_WORKER === "1" ? { cpus: 1 } : {}),
  },
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    root: process.cwd(),
    rules: {
      "globals.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
