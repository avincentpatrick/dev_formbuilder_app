import { ImageResponse } from "next/og";
import { FHSIS_RELEASE_LABEL } from "@/lib/db/fhsis";

// No isolated rate on a social card: its denominator and caveats belong beside it on the page.
export function healthServicesImage(area: string) {
  return new ImageResponse(
    <div
      style={{
        display: "flex",
        width: "100%",
        height: "100%",
        flexDirection: "column",
        justifyContent: "center",
        padding: 80,
        background: "#ffffff",
        color: "#1a1d1e",
        fontFamily: "sans-serif",
      }}
    >
      <div style={{ display: "flex", fontSize: 30, color: "#0a6e6e" }}>{FHSIS_RELEASE_LABEL}</div>
      <div
        style={{ display: "flex", fontSize: 54, marginTop: 24 }}
      >{`Health services · ${area}`}</div>
      <div style={{ display: "flex", fontSize: 26, marginTop: 30 }}>
        Immunisation, maternal care, environmental health, TB and workforce context
      </div>
    </div>,
    { width: 1200, height: 630 },
  );
}
