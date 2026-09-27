// Exercise the actual page functions, including the internal role tags.
const fs = require('fs');
const assert = require('assert');
const source = fs.readFileSync(__dirname + '/../pages/contingent-admin.php', 'utf8');
const build = source.slice(source.indexOf('function buildRows(data)'), source.indexOf('var rows = buildRows(res)'));
const filter = source.slice(source.indexOf('function applyFilters()'), source.indexOf('function genderFromMyKad'));
const run = new Function('data', 'filters', 'query', build + '\nvar rows = buildRows(data); var getFilters = () => filters; var $search = {val: () => query};\n' + filter + '\nreturn applyFilters();');
const data = {pengurus:[{nama:'Manager One',sukan_id:1}], jurulatih:[{nama:'Coach One',sukan_id:1}], atlet:[{nama:'Athlete One',sukan_id:1,kategori_id:10},{nama:'Athlete Two',sukan_id:2,kategori_id:20},{nama:'Athlete Three',sukan_id:1,kategori_id:11}]};
assert.equal(run(data,{},'').total,5);
assert.equal(run(data,{sportId:'1'},'').total,4);
const category = run(data,{sportId:'1',kategoriId:'10'},'');
assert.equal(category.total,3);
assert.equal(category.pengurus.length,1);
assert.equal(category.jurulatih.length,1);
assert.equal(category.atlet[0].nama,'Athlete One');
assert.equal(run(data,{sportId:'2'},'').atlet.length,1);
assert.equal(run(data,{},'three').total,1);
assert.equal(run(data,{sportId:'99'},'').total,0);
console.log('Passed: role groups, sport/category filters, search and empty results.');
