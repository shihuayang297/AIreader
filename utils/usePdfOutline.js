// src/utils/usePdfOutline.js

// 辅助函数：休眠
const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

// 1. 智能目录生成 (核心算法)
export const generateSmartOutline = async (doc) => {
  const structure = [];
  const fontSizes = {};
  
  // 采样前3页分析正文字体大小
  for(let p = 1; p <= Math.min(doc.numPages, 3); p++) {
      try {
        const page = await doc.getPage(p);
        const textContent = await page.getTextContent();
        textContent.items.forEach(item => {
            const height = Math.round(item.transform[3]); 
            if(height > 0) fontSizes[height] = (fontSizes[height] || 0) + 1;
        });
      } catch (e) {}
  }
  
  let bodyFontSize = 0;
  let maxCount = 0;
  for (const [size, count] of Object.entries(fontSizes)) {
      if (count > maxCount) { maxCount = count; bodyFontSize = parseInt(size); }
  }
  
  const titleKeywords = ["abstract", "introduction", "related work", "methodology", "conclusion", "references"];
  const maxPage = Math.min(doc.numPages, 20); 
  
  for (let i = 1; i <= maxPage; i++) {
    try {
      await sleep(50); // 防止卡顿
      const page = await doc.getPage(i);
      const textContent = await page.getTextContent();
      const items = textContent.items;
      let currentLine = "";
      let lastY = -1;
      let lineFontSize = 0;
      
      for (const item of items) {
          const fontSize = Math.round(item.transform[3]);
          if (Math.abs(item.transform[5] - lastY) > 5) {
              if (currentLine && lineFontSize > bodyFontSize) {
                  const cleanText = currentLine.trim().toLowerCase().replace(/^[0-9\.\s]+/, '');
                  if (titleKeywords.some(kw => cleanText.startsWith(kw))) {
                      const displayTitle = currentLine.trim();
                      if (!structure.find(s => s.title === displayTitle)) {
                          structure.push({ title: displayTitle, page: i });
                      }
                  }
              }
              currentLine = item.str;
              lastY = item.transform[5];
              lineFontSize = fontSize;
          } else {
              currentLine += item.str;
              lineFontSize = Math.max(lineFontSize, fontSize);
          }
      }
    } catch (e) {}
  }
  return structure;
}

// 2. 统一解析入口
export const parseOutline = async (doc) => {
  try {
    const outline = await doc.getOutline();
    if (outline && outline.length > 0) {
      const processItem = async (item) => {
        let pageNumber = null;
        if (item.dest) {
          try {
            const dest = typeof item.dest === 'string' ? await doc.getDestination(item.dest) : item.dest;
            if (dest) {
              const ref = dest[0];
              const pageIndex = await doc.getPageIndex(ref);
              pageNumber = pageIndex + 1; 
            }
          } catch (e) {}
        }
        return { title: item.title, page: pageNumber, items: item.items?.length ? await Promise.all(item.items.map(processItem)) : [] };
      };
      const structure = await Promise.all(outline.map(processItem));
      return structure;
    }
    // 如果没有原生目录，使用智能生成
    return await generateSmartOutline(doc);
  } catch (e) { 
      return []; 
  }
}