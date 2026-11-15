/*
 * The shapes the server sends. Kept beside the PHP that builds them:
 * BuzzConfig ← src/Api/ForumConfig.php (the forum's `buzz` attribute),
 * BuzzEvent ← src/Feed.php (each item of GET /api/buzz).
 */

/** The forum's `buzz` attribute: how and when events are shown. */
export interface BuzzConfig {
  style: 'toast' | 'ticker';
  tickerMode: 'slide' | 'marquee';
  side: 'left' | 'right';
  offsetX: number;
  offsetY: number;
  avatar: boolean;
  username: boolean;
  profileLink: boolean;
  /** Seconds between polls. */
  interval: number;
  /** Seconds each event stays on screen. */
  seconds: number;
  /** Cards on screen at once on a computer (phones always one). */
  maxToasts: number;
  pages: 'all' | 'home';
  hideComposing: boolean;
  /** Recent events to show on page load (0–3). */
  recent: number;
  optIn: boolean;
  realtime: boolean;
  cursor: number | null;
}

export interface BuzzUser {
  name: string;
  avatar: string | null;
  /** Null when names are masked. */
  slug: string | null;
}

export interface BuzzEvent {
  id: number;
  type: string;
  icon: string;
  /** Translated text; the member's name is marked with \u0001, the subject with \u0002. */
  text: string;
  user: BuzzUser | null;
  route: { name: string; params: Record<string, string | number> } | null;
  url: string | null;
  at: string;
}

/** The answer of GET /api/buzz. */
export interface BuzzPoll {
  cursor: number | null;
  events: BuzzEvent[];
}

/** One event type, as the admin payload lists it (app.data.buzzTypes). */
export interface BuzzType {
  key: string;
  setting: string;
  icon: string;
  name: string;
}
