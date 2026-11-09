import { healthServicesImage } from "@/components/health-services/social-image";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "Health services — FHSIS 2025 partial release";
export default function Image() {
  return healthServicesImage("Philippines");
}
