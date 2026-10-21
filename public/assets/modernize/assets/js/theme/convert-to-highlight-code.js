function escapeHtml(str) {
  return (
    str
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;")
      // .replace(/\n/g, "<br>") // Preserve new lines
      .replace(/\t/g, "&nbsp;&nbsp;&nbsp;&nbsp;")
  ); // Convert tabs to spaces
}

// Function to load and highlight HTML code
function loadAndHighlightHtml(filePath, outputElementId) {
  fetch(filePath)
    .then((response) => response.text())
    .then((data) => {
      let outputElement = document.getElementById(outputElementId);

      // Insert the fetched HTML content inside <pre><code>
      outputElement.innerHTML = `<pre><code class="language-html">${escapeHtml(
        data
      )}</code></pre>`;

      // Apply syntax highlighting (v9.15.8 uses highlightBlock for this version)
      const codeBlock = outputElement.querySelector("code");

      // Highlight the code block after it's added to the DOM
      hljs.highlightBlock(codeBlock); // This works for v9.15.8
    })
    .catch((error) =>
      console.error(`Error loading the file (${filePath}):`, error)
    );
}
