var userSettings = {
  Layout: "horizontal", // vertical | horizontal
  SidebarType: "full", // full | mini-sidebar
  BoxedLayout: true, // true | false
  Direction: "ltr", // ltr | rtl
  Theme: ((typeof localStorage !== "undefined" && localStorage.getItem("boss-theme")) || "light"), // light | dark — kalıcı tema (boss-theme localStorage)
  ColorTheme: "Blue_Theme", // Blue_Theme | Aqua_Theme | Purple_Theme | Green_Theme | Cyan_Theme | Orange_Theme
  cardBorder: false, // true | false
};
