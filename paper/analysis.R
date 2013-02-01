## Copyright 2010, Timothy Middelkoop. All Rights Reserved.

# Load data
library(plyr)
library(reshape)
rd <- read.csv(file="Brandon-2.csv",head=TRUE,sep=",")
d <- cast(rd, period ~ ename )

do.pdf <- TRUE

## Heat
if(do.pdf) pdf('graph-heat-2.pdf')

plot(e2.sc.IDN_Q ~ period,d ,type='l',col='green',
		ylim=c(-50000,150000),
		ylab='Heat Energy (W)',xlab=('Period (hour)')
)
lines(-e2.ahu.cool_Q ~ period,d,col='blue')
lines(e2.ahu.heat_Q ~ period,d,col='red')
lines(-e2.dhw.Demand_Q ~ period,d,col='orange')
lines(e2.ec.cwt_Q ~ period,d,col='purple')

lines(-e2.ice.storage_Q ~ period,d,col='blue',lty=4)
lines(e2.hhwt.storage_Q ~ period,d,col='red',lty=4)
lines(e2.dhwt.storage_Q ~ period,d,col='orange',lty=4)

legend("topleft", inset=-0.01,
		c("sc","dhw","cool","heat","ec","ice","hhwt","dhwt"),
		col=c('green','orange','blue','red','purple','blue','red','orange'),
		lty=c(1,1,1,1,1,4,4,4))

if(do.pdf) dev.off()

## Power
if(do.pdf) pdf('graph-electric.pdf')

plot( e2.dhwt.electric_Q ~ period,d ,type='l',col='red',
		ylab='Power Consumption (W)',xlab=('Period (hour)')
)

lines( e2.ec.electric.ice_Q + e2.ec.electric_Q ~ period,d,col='green')
lines( e2.ec.electric.ice_Q ~ period,d,col='blue',lty=4)
legend("topleft", inset=0.025,c("dhwt","cool","ice"),col=c('red','green','blue'),lty=c(1,1,4))

if(do.pdf) dev.off()

