const bcrypt = require('bcrypt');

const users = [
  { email: 'MiguelAnchia@miamibeachfl.gov',   pw: 'Penco1' },
  { email: 'RichardQuintela@miamibeachfl.gov', pw: 'Penco2' },
  { email: 'PeterDarley@miamibeachfl.gov',     pw: 'Penco3' },
  { email: 'GreciaTrabanino@miamibeachfl.gov', pw: 'MBFDSupport!' },
  { email: 'geralddeyoung@miamibeachfl.gov',   pw: 'MBFDGerry1' },
  { email: 'danielgato@miamibeachfl.gov',      pw: 'Gato1234!' },
  { email: 'victorwhite@miamibeachfl.gov',     pw: 'Vic1234!' },
  { email: 'ClaudioNavas@miamibeachfl.gov',    pw: 'Flea1234!' },
  { email: 'michaelsica@miamibeachfl.gov',     pw: 'Sica1234!' },
];

(async () => {
  const results = [];
  for (const u of users) {
    const hash = await bcrypt.hash(u.pw, 10);
    results.push({ email: u.email, hash });
  }
  // Output as SQL
  console.log('-- NocoBase user password update SQL');
  for (const r of results) {
    console.log(`UPDATE users SET password='${r.hash}' WHERE email='${r.email}';`);
  }
  // Also output INSERT for missing users (GeraldDeYoung and training users)
  const now = new Date().toISOString();
  const missing = [
    { email: 'geralddeyoung@miamibeachfl.gov', nick: 'Gerald DeYoung', username: 'GeraldDeYoung', pw: 'MBFDGerry1' },
    { email: 'danielgato@miamibeachfl.gov',    nick: 'Daniel Gato',    username: 'DanielGato',    pw: 'Gato1234!' },
    { email: 'victorwhite@miamibeachfl.gov',   nick: 'Victor White',   username: 'VictorWhite',   pw: 'Vic1234!' },
    { email: 'ClaudioNavas@miamibeachfl.gov',  nick: 'Claudio Navas',  username: 'ClaudioNavas',  pw: 'Flea1234!' },
    { email: 'michaelsica@miamibeachfl.gov',   nick: 'Michael Sica',   username: 'MichaelSica',   pw: 'Sica1234!' },
  ];
  console.log('\n-- INSERT missing users');
  for (const u of missing) {
    const hash = results.find(r => r.email === u.email)?.hash;
    console.log(`INSERT INTO users (email, nickname, username, password, created_at, updated_at) VALUES ('${u.email}', '${u.nick}', '${u.username}', '${hash}', '${now}', '${now}') ON CONFLICT (email) DO UPDATE SET password=EXCLUDED.password;`);
  }
})();
