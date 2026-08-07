const url = process.argv[2] || "https://perigallo.com/la-perigalla-01/";
const response = await fetch(url, { redirect: "manual" });

if (response.status !== 200) {
  throw new Error(`Expected HTTP 200 for ${url}, received ${response.status}.`);
}

const html = await response.text();
for (const marker of ["id=\"root\"", "/la-perigalla-01/assets/", "La Perigalla 01"]) {
  if (!html.includes(marker)) throw new Error(`La Perigalla smoke check is missing ${marker}.`);
}

console.log(`La Perigalla smoke check passed: ${url}`);
