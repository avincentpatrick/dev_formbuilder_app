import { ImageResponse } from "next/og";

export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

// Portal-branded share card. Deliberately static (no DB read) — unlike the BHW
// dataset OG at app/bhw/opengraph-image.tsx — since the portal shows no counts.
export default function Image() {
  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          justifyContent: "center",
          padding: 80,
          background: "#ffffff",
          fontFamily: "system-ui, sans-serif",
        }}
      >
        <div style={{ fontSize: 30, color: "#0a6e6e", fontWeight: 600 }}>Equity in Health</div>
        <div style={{ fontSize: 56, fontWeight: 700, color: "#1a1d1e", marginTop: 20 }}>
          Data &amp; innovation
        </div>
        <div style={{ display: "flex", fontSize: 28, color: "#57616a", marginTop: 30 }}>
          A repository of open, publicly available health datasets — no personal data.
        </div>
      </div>
    ),
    { ...size },
  );
}
