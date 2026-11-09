import { notFound } from "next/navigation";
import { healthServicesImage } from "@/components/health-services/social-image";
import { getGeoByCode } from "@/lib/db/geo";
import { isFhsisLevel } from "@/lib/db/fhsis";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const alt = "Health services — FHSIS 2025 partial release";
export default async function Image({
  params,
}: {
  params: Promise<{ geoLevel: string; geoCode: string }>;
}) {
  const { geoLevel, geoCode } = await params;
  if (!isFhsisLevel(geoLevel)) notFound();
  const geo = await getGeoByCode(geoCode);
  if (!geo || geo.geoLevel !== geoLevel) notFound();
  return healthServicesImage(geo.geoName);
}
