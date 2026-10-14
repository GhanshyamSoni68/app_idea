import math,wave,struct,json,zipfile,base64
from pathlib import Path
R=Path('/workspace/scratch/570d4d755117/devstep-sounds'); SR=44100
# Original motifs: D major pentatonic. Shared D-F#-A signature.
specs=[
('tap','Soft tap','Primary buttons only; avoid every keystroke.',[(0,86,.09,.24)],.16),
('next','Next step','Advancing to the next exercise.',[(0,74,.14,.35),(.09,81,.21,.38)],.42),
('correct','Correct answer','An answer accepted; small, warm reward.',[(0,74,.25,.48),(.105,78,.25,.44),(.21,81,.38,.43)],.8),
('try-again','Try again','A gentle invitation to retry, without an alarm.',[(0,69,.27,.38),(.20,71,.32,.33)],.76),
('hint','Aha / hint','A hint revealed or explanation understood.',[(0,78,.35,.35),(.14,85,.48,.34)],.91),
('saved','Progress saved','Explicit save confirmation; keep autosave silent.',[(0,62,.20,.36),(.09,74,.26,.30)],.55),
('lesson-complete','Lesson complete','Finished a practice session.',[(0,74,.32,.43),(.13,78,.32,.40),(.26,81,.34,.40),(.43,86,.57,.41),(.43,62,.57,.20)],1.25),
('milestone','Roadmap milestone','Reserved for modules or roadmap completion.',[(0,62,.42,.30),(0,74,.30,.40),(.16,78,.30,.37),(.32,81,.37,.39),(.53,86,.65,.40),(.53,78,.65,.18),(.90,90,.60,.26),(.90,81,.60,.18),(.90,62,.60,.18)],1.85),
('rest','Rest / pause','Calm confirmation that the roadmap is paused.',[(0,69,.47,.30),(.22,66,.55,.28),(.45,62,.60,.27)],1.35),
('welcome','Signature welcome','Optional, once on an intentional session start.',[(0,62,.38,.30),(0,74,.38,.37),(.18,78,.38,.35),(.36,81,.63,.36)],1.28)
]
manifest=[]
for slug,title,use,notes,length in specs:
 n=int(length*SR); data=[0.0]*n
 for start,midi,duration,amp in notes:
  f=440*2**((midi-69)/12); off=int(start*SR)
  for k in range(int(duration*SR)):
   t=k/SR; env=(1-math.exp(-t/.006))*math.exp(-t/(duration*.34))*min(1,max(0,(duration-t)/.035))
   # Rounded marimba / digital droplet timbre with very quiet upper partials.
   tone=math.sin(2*math.pi*f*t)+.22*math.exp(-t/.07)*math.sin(2*math.pi*2*f*t)+.065*math.exp(-t/.04)*math.sin(2*math.pi*3*f*t)
   idx=off+k
   if idx<n:data[idx]+=amp*env*tone
 # restrained stereo-free short echo; no external samples or voices
 original=data[:]
 for delay,gain in [(.057,.10),(.109,.045)]:
  d=int(delay*SR)
  for i in range(d,n):data[i]+=original[i-d]*gain
 peak=max(abs(x) for x in data); scale=min(1,.78/peak)
 pcm=b''.join(struct.pack('<h',round(max(-1,min(1,x*scale))*32767)) for x in data)
 with wave.open(str(R/(slug+'.wav')),'wb') as w:w.setparams((1,2,SR,0,'NONE','not compressed'));w.writeframes(pcm)
 rms=(sum((x*scale)**2 for x in data)/n)**.5
 manifest.append(dict(id=slug,title=title,use=use,file=slug+'.wav',duration_seconds=length,peak_dbfs=round(20*math.log10(peak*scale),1),rms_dbfs=round(20*math.log10(rms),1)))
rows=''
for x in manifest:
 src='data:audio/wav;base64,'+base64.b64encode((R/x['file']).read_bytes()).decode()
 rows+=f'<article><div><h2>{x["title"]}</h2><p>{x["use"]}</p></div><audio controls preload="none" src="{src}"></audio></article>'
html='''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>DevStep · Sound studio</title><style>*{box-sizing:border-box}body{font:16px system-ui;background:#f5f7fd;color:#182441;margin:0;padding:40px 20px}main{max-width:900px;margin:auto}.tag{font-size:12px;color:#2455da;letter-spacing:.15em;font-weight:700}h1{font-size:44px;margin:12px 0}header p{max-width:680px;line-height:1.6;color:#5b6880}article{background:white;border:1px solid #dde4f0;border-radius:18px;padding:20px 24px;margin:12px 0;display:flex;align-items:center;justify-content:space-between;gap:20px}h2{font-size:18px;margin:0 0 6px}p{font-size:14px;color:#5b6880;margin:0}audio{width:290px;max-width:100%}header{margin-bottom:30px}footer{font-size:13px;color:#5b6880;margin-top:24px;line-height:1.6}@media(max-width:650px){article{display:block}audio{margin-top:15px;width:100%}h1{font-size:34px}}</style><main><header><div class="tag">DEVSTEP / ORIGINAL SOUND STUDIES</div><h1>Progress you can hear.</h1><p>A warm, rounded digital chime family. The shared three-note upward motif connects small steps to major milestones. Press play to compare each cue.</p></header>'''+rows+'''<footer>Original synthesized audio · 44.1 kHz / 16-bit mono WAV · No external samples.<br>One cue plays at a time. Use a sound toggle and never rely on sound alone to convey feedback. Audition on a phone before shipping.</footer></main><script>document.querySelectorAll('audio').forEach(a=>a.addEventListener('play',()=>document.querySelectorAll('audio').forEach(b=>{if(b!==a){b.pause();b.currentTime=0}})))</script></html>'''
(R/'DevStep-Sound-Preview.html').write_text(html)
(R/'manifest.json').write_text(json.dumps(manifest,indent=2))
(R/'README.md').write_text('''# DevStep sound pack v1

Ten original synthesized cues. Open DevStep-Sound-Preview.html in a browser to audition them offline. This is a first sound direction, not a replica of Duolingo audio.

## Signature
D–F#–A ascending motif, soft digital marimba / droplet timbre; milestones resolve upward to D. Small UI actions use fragments. No external recordings or samples.

## Integration
- Use WAV files directly via HTMLAudioElement / your app audio player. Load after user interaction; respect browser autoplay restrictions.
- Store a Sound effects preference; make mute available immediately.
- Use event IDs from manifest.json. Play one cue at a time; stop the old cue before a new one.
- Trigger correct OR try-again only after authoritative feedback, not on every attempt submission.
- If an answer also ends a lesson, play lesson-complete only. Milestone takes priority over lesson-complete.
- Keep typing, autosave, scrolling, errors during typing, and passive navigation silent. No rapid repeated tap sounds.
- Welcome only once on intentional session start. Rest never sounds like failure.
- Pair every cue with visible feedback. No sound for missed practice or inactivity.
- These files have been checked for valid PCM, lengths and peak headroom. Final listening / loudness balancing on phone speakers and headphones is still required.

## Format
44.1 kHz, 16-bit mono PCM WAV. Short cues, no voice. Generated with the included build.py for reproducible tuning. No repository changes made.
''')
with zipfile.ZipFile(R.parent/'DevStep-Sound-Pack.zip','w',zipfile.ZIP_DEFLATED) as z:
 for p in sorted(R.iterdir()):z.write(p,'DevStep-Sound-Pack/'+p.name)
for x in manifest:
 with wave.open(str(R/x['file'])) as w:assert w.getnframes()>0 and w.getnchannels()==1
print(json.dumps(manifest,indent=2));print('Pack created and WAV structure verified')
