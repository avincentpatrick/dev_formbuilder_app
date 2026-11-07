import { readFile } from "node:fs/promises";
import { join } from "node:path";
import { ImageResponse } from "next/og";
import { IconnectLogo } from "@/components/branding/iconnect-logo";

export const alt = "iconnect — health data and innovation";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

// No database or remote fonts: sharing the portal uses the same outlined wordmark.
export default async function Image() {
  const equity = await readFile(join(process.cwd(), "public/img/equity-mark.png"));
  return new ImageResponse(
    <div
      style={{
        width: "100%",
        height: "100%",
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        justifyContent: "center",
        padding: 70,
        background: "#ffffff",
        color: "#082b56",
        fontFamily: "system-ui, sans-serif",
        borderBottom: "6px solid #009da5",
      }}
    >
      <IconnectLogo width={780} height={158} color="#082b56" />
      <div style={{ fontSize: 28, color: "#57616a", marginTop: 28 }}>
        Open health data, connected insights, and innovations
      </div>
      <div
        style={{
          display: "flex",
          width: "100%",
          alignItems: "center",
          justifyContent: "center",
          marginTop: 80,
          paddingTop: 24,
          borderTop: "1px solid #b3d9d8",
        }}
      >
        {/* ImageResponse requires a native img, not next/image. */}
        <img
          src={`data:image/png;base64,${equity.toString("base64")}`}
          width={154}
          height={50}
          alt="Equity in Health"
        />
        <div
          style={{
            marginLeft: 24,
            paddingLeft: 24,
            borderLeft: "1px solid #b3d9d8",
            fontSize: 24,
            letterSpacing: 3,
          }}
        >
          innovation
        </div>
      </div>
    </div>,
    { ...size },
  );
}
