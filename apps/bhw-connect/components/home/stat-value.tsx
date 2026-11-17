/** Count and percentage remain a single readable figure, scaled to the available card width. */
export function StatValue({ value, hero = false }: { value: string; hero?: boolean }) {
  const paired = /\d.*%/.test(value) && !value.includes("unavailable");
  return (
    <div className="min-w-0" style={{ containerType: "inline-size" }}>
      <p
        className={`mt-1 font-semibold tracking-tight tabular-nums ${paired ? "whitespace-nowrap" : hero ? "text-[3rem] sm:text-[3.5rem]" : "text-4xl"}`}
        style={
          paired
            ? { fontSize: `min(${hero ? "3.5rem" : "2.25rem"}, ${100 / (value.length * 0.62)}cqw)` }
            : undefined
        }
      >
        {value}
      </p>
    </div>
  );
}
