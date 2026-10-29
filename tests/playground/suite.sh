#!/bin/bash
cd /tmp/pg
B=http://127.0.0.1:9400; export WPD_BASE=$B
p() { curl -sL "$B/$1" > /dev/null; curl -sL --max-time 600 "$B/$1" > "$2"; echo "== $2: $(tail -1 "$2")"; }
s() { bash "$1" > "$2" 2>&1; echo "== $2: $(tail -1 "$2")"; }
# Ждём окончания установки по blueprint: «Ready» в логе сервера приходит раньше.
for i in $(seq 1 60); do curl -sL --max-time 30 http://127.0.0.1:9400/wp-login.php | grep -q "user_login" && break; sleep 5; done
p wpd-test-a.php resA.txt; s phaseB.sh resB.txt
p wpd-test-d.php resD.txt; s phaseE.sh resE.txt
p wpd-test-g.php resG1.txt; s phaseG.sh resG2.txt
p wpd-test-h.php resH1.txt; s phaseH.sh resH2.txt
p wpd-test-i.php resI1.txt; s phaseI.sh resI2.txt
p wpd-test-k.php resK1.txt; s phaseK.sh resK2.txt
p wpd-test-l.php resL1.txt; s phaseL.sh resL2.txt
p wpd-test-m.php resM1.txt; s phaseM.sh resM2.txt
p wpd-test-p.php resP1.txt; s phaseP.sh resP2.txt
p wpd-test-q.php resQ1.txt; s phaseQ.sh resQ2.txt
p wpd-test-r.php resR1.txt; s phaseR.sh resR2.txt
p wpd-test-s.php resS1.txt; s phaseS.sh resS2.txt
p wpd-test-t.php resT1.txt; s phaseT.sh resT2.txt
p wpd-test-u.php resU1.txt; s phaseU.sh resU2.txt
p wpd-test-w.php resW1.txt; s phaseW.sh resW2.txt
p wpd-test-x.php resX1.txt; s phaseX.sh resX2.txt
p wpd-test-y.php resY1.txt; s phaseY.sh resY2.txt
(cd /tmp/jsdom && node wpd-js-test.cjs /tmp/pg/f_features.html /home/user/wp-design/wp-olympiad-design/assets/js/wpd.js /tmp/pg/h_components.html > /tmp/pg/resJ.txt 2>&1); echo "== resJ.txt: $(tail -1 resJ.txt)"
p wpd-test-c.php resC.txt
echo SUITE_DONE
