const url = process.argv[2] || "https://perigallo.com/formulario/";
const response = await fetch(url, { redirect: "manual" });

if (response.status !== 200) {
  throw new Error(`Expected HTTP 200 for ${url}, received ${response.status}.`);
}

const html = await response.text();
for (const marker of ["id=\"form-wrapper\"", "privacy-accepted", "/api/formulario"]) {
  if (!html.includes(marker)) throw new Error(`Public form smoke check is missing ${marker}.`);
}

console.log(`Public form smoke check passed: ${url}`);
