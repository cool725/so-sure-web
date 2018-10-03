// trackSimple.js

function simpleTrack(name, callback) {
    let url = '/ops/track/' + name;
    $.get(url, callback);
}


export default simpleTrack;
