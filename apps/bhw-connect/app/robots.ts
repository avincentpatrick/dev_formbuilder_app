import type { MetadataRoute } from "next";
import { withBasePath } from "@/lib/base-path";
import { SITE_URL } from "@/lib/site-url";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: "*",
        allow: "/",
        disallow: [withBasePath("/api/"), withBasePath("/admin")],
      },
    ],
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
