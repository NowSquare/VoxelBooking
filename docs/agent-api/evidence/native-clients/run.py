import json,os,subprocess,sys
from pathlib import Path
from fixture_guard import client_root
root=client_root(sys.argv[1])
f=json.loads((root/'fixture.json').read_text());client=sys.argv[2];phase=sys.argv[3]
prompt=Path(sys.argv[4]).read_text();env=os.environ.copy();env['VOXELBOOKING_CLIENT_TEST_TOKEN']=f['clients'][client]['token']
if client=='codex':
 cmd=['codex','exec','--ignore-user-config','--ephemeral','--skip-git-repo-check','--sandbox','read-only','--color','never','--json','-C',str(root),'-c','mcp_servers.voxelbooking.url='+json.dumps(f['base']+'/mcp'),'-c','mcp_servers.voxelbooking.bearer_token_env_var="VOXELBOOKING_CLIENT_TEST_TOKEN"','-c','mcp_servers.voxelbooking.startup_timeout_sec=30','-c','features.apps=false','-o',str(root/(client+'-'+phase+'-final.txt')),prompt]
 for tool in ['booking_create','booking_reschedule','booking_status_set']:
  cmd[-1:-1]=['-c','mcp_servers.voxelbooking.tools.'+tool+'.approval_mode="approve"']
else:
 cmd=['claude','--print','--restricted','--setting-sources','','--strict-mcp-config','--mcp-config',str(root/'claude-mcp.json'),'--no-session-persistence','--disable-slash-commands','--no-chrome','--tools','','--allowedTools','mcp__voxelbooking__*','--permission-mode','dontAsk','--output-format','stream-json','--verbose',prompt]
with (root/(client+'-'+phase+'.jsonl')).open('w') as output:
 result=subprocess.run(cmd,cwd=root,env=env,stdout=output,stderr=subprocess.STDOUT,timeout=180)
print(json.dumps({'client':client,'phase':phase,'exit':result.returncode,'log':str(root/(client+'-'+phase+'.jsonl'))}))
