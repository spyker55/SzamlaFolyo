// A handful of stroke icons, drawn here rather than pulled from a package.
//
// The whole set is used in two places — the sidebar and the empty states — and
// an icon library would be a dependency, a bundle and a licence for eleven
// paths. They are decorative: every one of them sits next to its own label, so
// they are hidden from screen readers.

type IconProps = { className?: string };

function Svg({
  className = "h-4 w-4",
  children,
}: IconProps & { children: React.ReactNode }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.7}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      {children}
    </svg>
  );
}

export function IconInbox(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M3 13h4l1.5 3h7L17 13h4" />
      <path d="M4.5 6.5 3 13v5a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-5l-1.5-6.5A2 2 0 0 0 17.6 5H6.4a2 2 0 0 0-1.9 1.5Z" />
    </Svg>
  );
}

export function IconFolder(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M3 7a2 2 0 0 1 2-2h3.6a2 2 0 0 1 1.5.7l1 1.3H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" />
    </Svg>
  );
}

export function IconBook(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H19a1 1 0 0 1 1 1v14" />
      <path d="M4 4.5v13A2.5 2.5 0 0 0 6.5 20H20" />
      <path d="M8 7h8M8 11h6" />
    </Svg>
  );
}

export function IconUsers(props: IconProps) {
  return (
    <Svg {...props}>
      <circle cx="9" cy="8" r="3.2" />
      <path d="M3.5 19a5.5 5.5 0 0 1 11 0" />
      <path d="M16.5 5.6a3.2 3.2 0 0 1 0 5.8M17 14.2a5.5 5.5 0 0 1 3.5 4.8" />
    </Svg>
  );
}

export function IconWallet(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H17a1 1 0 0 1 1 1v1.5" />
      <path d="M3 7.5V17a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2H5.5A2.5 2.5 0 0 1 3 7.5Z" />
      <circle cx="17" cy="13.5" r="1" fill="currentColor" stroke="none" />
    </Svg>
  );
}

export function IconDownload(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M12 3v11" />
      <path d="m8 10.5 4 4 4-4" />
      <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
    </Svg>
  );
}

export function IconPlus(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M12 5v14M5 12h14" />
    </Svg>
  );
}

export function IconSearch(props: IconProps) {
  return (
    <Svg {...props}>
      <circle cx="11" cy="11" r="6.5" />
      <path d="m16 16 4.5 4.5" />
    </Svg>
  );
}

export function IconLogout(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3" />
      <path d="M10 8 6 12l4 4M6 12h9" />
    </Svg>
  );
}

export function IconUpload(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M12 20V9" />
      <path d="m8 12.5 4-4 4 4" />
      <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
    </Svg>
  );
}

export function IconMail(props: IconProps) {
  return (
    <Svg {...props}>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m4 7 8 5.5L20 7" />
    </Svg>
  );
}

// A box with a lid and a label line: the archive, not a folder.
export function IconArchive(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M3 5.5A1.5 1.5 0 0 1 4.5 4h15A1.5 1.5 0 0 1 21 5.5V8H3Z" />
      <path d="M4.5 8h15v10a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 18Z" />
      <path d="M10 12h4" />
    </Svg>
  );
}

// A clock with a turned-back hand: the log is the register's own history.
export function IconHistory(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M3.5 9.5A9 9 0 1 1 3 12" />
      <path d="M3 4.5V9.5H8" />
      <path d="M12 7.5V12l3 1.8" />
    </Svg>
  );
}

// A public building with columns: the tax authority.
export function IconLandmark(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M3 9.5 12 4l9 5.5" />
      <path d="M6 10v7M10 10v7M14 10v7M18 10v7" />
      <path d="M3.5 20h17" />
    </Svg>
  );
}

export function IconArrowLeft(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M19 12H5" />
      <path d="m11 6-6 6 6 6" />
    </Svg>
  );
}

export function IconAlert(props: IconProps) {
  return (
    <Svg {...props}>
      <path d="M12 9v4M12 16.5h.01" />
      <path d="M10.3 4.3 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z" />
    </Svg>
  );
}
