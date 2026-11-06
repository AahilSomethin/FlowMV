export function BrandMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 32 32" className={className} aria-hidden="true">
      <circle cx="16" cy="16" r="10.25" fill="none" stroke="currentColor" strokeWidth="2.25" />
      <path
        d="M9.2 18.6c2.2-6.4 5.2-8.8 8.2-7.4 2.4 1.1 3.2 3.6 2.2 6.2"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
      />
      <circle cx="9.2" cy="18.6" r="1.7" fill="currentColor" />
      <circle cx="17.4" cy="11.2" r="1.7" fill="currentColor" />
      <circle cx="19.6" cy="17.4" r="1.7" fill="currentColor" />
    </svg>
  );
}
