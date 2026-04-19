/*
 * Forum data from phpbb-vibed (localhost:8181)
 */

const FORUM_TYPE_CAT = 0;
const FORUM_TYPE_POST = 1;
const FORUM_TYPE_LINK = 2;

const forums = [
  // ── DYSKUSJE OGÓLNE ────────────────────────────────────
  {
    forum_id: 1,
    parent_id: 0,
    forum_type: FORUM_TYPE_CAT,
    forum_name: 'DYSKUSJE OGÓLNE',
    forum_desc: '',
    left_id: 1,
    right_id: 12,
  },
  {
    forum_id: 2,
    parent_id: 1,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Przedstaw się',
    forum_desc: 'Powitaj społeczność i opowiedz o sobie',
    forum_posts_approved: 12,
    forum_topics_approved: 3,
    forum_last_post_subject: 'Re: Pozdrawiam z Krakowa',
    forum_last_post_time: 1744577040, // Mon Apr 13 21:24
    forum_last_poster_name: 'barbara',
    forum_last_poster_colour: '',
    left_id: 2,
    right_id: 3,
  },
  {
    forum_id: 3,
    parent_id: 1,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Luźne rozmowy',
    forum_desc: 'Tematy niezwiązane z żadną konkretną kategorią',
    forum_posts_approved: 15,
    forum_topics_approved: 3,
    forum_last_post_subject: 'Re: Co robicie w weekend?',
    forum_last_post_time: 1743724000, // Sat Apr 04 00:26
    forum_last_poster_name: 'radek',
    forum_last_poster_colour: '',
    left_id: 4,
    right_id: 5,
  },
  {
    forum_id: 4,
    parent_id: 1,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Newsy i ogłoszenia',
    forum_desc: 'Aktualności i ważne informacje',
    forum_posts_approved: 12,
    forum_topics_approved: 3,
    forum_last_post_subject: 'Re: Meetup Warszawa',
    forum_last_post_time: 1744311540, // Fri Apr 10 18:59
    forum_last_poster_name: 'yuri',
    forum_last_poster_colour: '',
    left_id: 6,
    right_id: 7,
  },
  {
    forum_id: 5,
    parent_id: 1,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Pytania i odpowiedzi',
    forum_desc: 'Zadaj pytanie, uzyskaj pomoc',
    forum_posts_approved: 15,
    forum_topics_approved: 3,
    forum_last_post_subject: 'Re: Problem z CORS',
    forum_last_post_time: 1744578840, // Mon Apr 13 21:54
    forum_last_poster_name: 'dawid',
    forum_last_poster_colour: '',
    left_id: 8,
    right_id: 9,
  },
  {
    forum_id: 6,
    parent_id: 1,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Propozycje i pomysły',
    forum_desc: 'Podziel się swoimi pomysłami',
    forum_posts_approved: 7,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: Dark mode dla forum',
    forum_last_post_time: 1743436980, // Tue Mar 31 17:03
    forum_last_poster_name: 'dorota',
    forum_last_poster_colour: '',
    left_id: 10,
    right_id: 11,
  },

  // ── TECHNOLOGIA ─────────────────────────────────────────
  {
    forum_id: 7,
    parent_id: 0,
    forum_type: FORUM_TYPE_CAT,
    forum_name: 'TECHNOLOGIA',
    forum_desc: '',
    left_id: 13,
    right_id: 24,
  },
  {
    forum_id: 8,
    parent_id: 7,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Programowanie',
    forum_desc: 'Dyskusje o językach programowania i narzędziach',
    forum_posts_approved: 16,
    forum_topics_approved: 3,
    forum_last_post_subject: 'Re: Rust - czy warto?',
    forum_last_post_time: 1744592040, // Tue Apr 14 02:54
    forum_last_poster_name: 'daria',
    forum_last_poster_colour: '',
    left_id: 14,
    right_id: 15,
  },
  {
    forum_id: 9,
    parent_id: 7,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Sprzęt komputerowy',
    forum_desc: 'Recenzje, porady, konfiguracje PC',
    forum_posts_approved: 9,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: MacBook Pro M5',
    forum_last_post_time: 1744617360, // Tue Apr 14 09:56
    forum_last_poster_name: 'xena',
    forum_last_poster_colour: '',
    left_id: 16,
    right_id: 17,
  },
  {
    forum_id: 10,
    parent_id: 7,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Systemy operacyjne',
    forum_desc: 'Linux, Windows, macOS i inne',
    forum_posts_approved: 10,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: Linux Mint vs Ubuntu',
    forum_last_post_time: 1743839400, // Sun Apr 05 08:30
    forum_last_poster_name: 'cyprian',
    forum_last_poster_colour: '',
    left_id: 18,
    right_id: 19,
  },
  {
    forum_id: 11,
    parent_id: 7,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Sieci i bezpieczeństwo',
    forum_desc: 'Networking, cybersecurity, VPN',
    forum_posts_approved: 9,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: VPN który wybrać?',
    forum_last_post_time: 1742933640, // Thu Mar 26 21:14
    forum_last_poster_name: 'dawid',
    forum_last_poster_colour: '',
    left_id: 20,
    right_id: 21,
  },
  {
    forum_id: 12,
    parent_id: 7,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Gry i rozrywka',
    forum_desc: 'Gaming, esport, recenzje gier',
    forum_posts_approved: 10,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: GTA VI opinie',
    forum_last_post_time: 1744409580, // Sun Apr 12 01:13
    forum_last_poster_name: 'norbert',
    forum_last_poster_colour: '',
    left_id: 22,
    right_id: 23,
  },

  // ── SPOŁECZNOŚĆ ─────────────────────────────────────────
  {
    forum_id: 13,
    parent_id: 0,
    forum_type: FORUM_TYPE_CAT,
    forum_name: 'SPOŁECZNOŚĆ',
    forum_desc: '',
    left_id: 25,
    right_id: 36,
  },
  {
    forum_id: 14,
    parent_id: 13,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Sport i zdrowie',
    forum_desc: 'Aktywność fizyczna, dieta, wellness',
    forum_posts_approved: 10,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: Bieganie jak zacząć',
    forum_last_post_time: 1743688320, // Fri Apr 03 13:32
    forum_last_poster_name: 'oskar',
    forum_last_poster_colour: '',
    left_id: 26,
    right_id: 27,
  },
  {
    forum_id: 15,
    parent_id: 13,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Muzyka i film',
    forum_desc: 'Rekomendacje, recenzje, dyskusje',
    forum_posts_approved: 9,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: Albumy roku 2026',
    forum_last_post_time: 1744325520, // Fri Apr 10 22:52
    forum_last_poster_name: 'hanna',
    forum_last_poster_colour: '',
    left_id: 28,
    right_id: 29,
  },
  {
    forum_id: 16,
    parent_id: 13,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Podróże',
    forum_desc: 'Relacje z podróży, porady, miejsca warte odwiedzenia',
    forum_posts_approved: 10,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: Tanie loty Europa',
    forum_last_post_time: 1744590840, // Tue Apr 14 01:34
    forum_last_poster_name: 'dorota',
    forum_last_poster_colour: '',
    left_id: 30,
    right_id: 31,
  },
  {
    forum_id: 17,
    parent_id: 13,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Książki i nauka',
    forum_desc: 'Literatura, kursy, samorozwój',
    forum_posts_approved: 10,
    forum_topics_approved: 2,
    forum_last_post_subject: 'Re: Książki o programowaniu',
    forum_last_post_time: 1743231720, // Sun Mar 29 08:02
    forum_last_poster_name: 'patrycja',
    forum_last_poster_colour: '',
    left_id: 32,
    right_id: 33,
  },
  {
    forum_id: 18,
    parent_id: 13,
    forum_type: FORUM_TYPE_POST,
    forum_name: 'Giełda i handel',
    forum_desc: 'Kupię, sprzedam, zamienię',
    forum_posts_approved: 11,
    forum_topics_approved: 3,
    forum_last_post_subject: 'Re: Kupię klawiaturę mechanic…',
    forum_last_post_time: 1744135500, // Wed Apr 08 19:05
    forum_last_poster_name: 'marta',
    forum_last_poster_colour: '',
    left_id: 34,
    right_id: 35,
  },
];

const stats = {
  total_posts: '165',
  total_topics: '36',
  total_users: '51',
  newest_user: 'dawid',
  newest_user_colour: '',
};

const onlineUsers = {
  total_online: '1 user online :: 0 registered, 0 hidden and 1 guest',
  users: [],
  legend: [
    { name: 'Administrators', colour: 'AA0000' },
    { name: 'Global moderators', colour: '00AA00' },
  ],
};

const birthdays = [];

export { forums, stats, onlineUsers, birthdays, FORUM_TYPE_CAT, FORUM_TYPE_POST, FORUM_TYPE_LINK };
